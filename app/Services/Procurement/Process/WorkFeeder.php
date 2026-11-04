<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\FairnessCursor;
use App\Dto\Procurement\Process\LaneLoad;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Enums\Procurement\Process\AdmissionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\AdmissionRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\HandlerAuthorityRepository;
use App\Repositories\Procurement\Process\LaneRepository;

/**
 * Feeder tick одной parent lane: единый protocol для всех parents — durable RR
 * между continuously eligible cohorts, затем RR sibling stages внутри выбранного cohort, затем
 * bounded выборка work (age900 первыми). При >=2 eligible cohorts новый admission cohort требует
 * active(parent, cohort) < ceil(W/2); уже выданные slots не отзываются. Refill только при
 * outstanding <= low_watermark, не больше refill_max grants за tick. Выбор — unlocked pre-read;
 * cursor, W/C и cap повторно сверяет admit tx под lane locks. Новые cohort scopes создаются
 * отдельной ensureScopes tx до admission (dynamic cohort arrival), admission scopes не создаёт.
 */
final readonly class WorkFeeder
{
    /** Bounded число попыток выбора на один grant: cursor races не превращаются в busy loop. */
    private const int PASSES_PER_GRANT = 4;

    public function __construct(
        private ProcessPolicy $policy,
        private StageRegistry $registry,
        private AdmissionRepository $admissions,
        private ControlRepository $controls,
        private HandlerAuthorityRepository $handlers,
        private LaneRepository $lanes,
        private ProcessTransaction $transaction,
    ) {}

    /** Один tick parent lane; возвращает число committed admissions. */
    public function tick(string $parentLane): int
    {
        $stages = array_values(array_filter(
            $this->policy->stagesOfParentLane($parentLane),
            fn (StageCode $code): bool => SchemaSupportedStages::supports($code) && $this->registry->handlerVersion($code) !== null,
        ));
        if ($stages === []) {
            return 0;
        }
        $laneKeys = array_values(array_unique(array_merge(...array_map(fn (StageCode $code): array => $this->policy->requiredLanes($code), $stages))));
        $parent = $this->lanes->loads($laneKeys)[$parentLane] ?? null;
        if ($parent === null || $parent->outstanding > $parent->lowWatermark) {
            return 0;
        }

        $granted = 0;
        $passes = $parent->refillMax * self::PASSES_PER_GRANT;
        while ($granted < $parent->refillMax && $passes-- > 0) {
            $loads = $this->lanes->loads($laneKeys);
            $current = $loads[$parentLane];
            if ($current->outstanding >= $current->maxOutstanding) {
                break;
            }
            $eligible = $this->eligibleCohorts($stages, $loads);
            if ($eligible === []) {
                break;
            }
            $outcome = $this->admitNext($current, $eligible);
            if ($outcome === AdmissionStatus::Admitted) {
                $granted++;
            } elseif (! in_array($outcome, [AdmissionStatus::CursorMoved, AdmissionStatus::SnapshotChanged], true)) {
                break;
            }
        }

        return $granted;
    }

    /**
     * Следующий grant по cohort RR → stage RR → candidates. CursorMoved означает конкурентный
     * committed admission: выбор повторяется по новому cursor.
     *
     * @param  array<string, list<StageCode>>  $eligible
     */
    private function admitNext(LaneLoad $parent, array $eligible): ?AdmissionStatus
    {
        $cursor = $parent->cursor;
        $cohorts = array_keys($eligible);
        $count = count($cohorts);
        $cap = (int) ceil($parent->maxOutstanding / 2);
        $active = $count >= 2 ? $this->admissions->activeByCohort($parent->id->value) : [];
        foreach (FairnessCursor::after($cohorts, $cursor->lastCohort) as $cohort) {
            if ($count >= 2 && ($active[$cohort] ?? 0) >= $cap) {
                continue;
            }
            $values = array_map(static fn (StageCode $code): string => $code->value, $eligible[$cohort]);
            foreach (FairnessCursor::after($values, $cursor->lastStage($cohort)?->value) as $value) {
                foreach ($this->admissions->candidates(StageCode::from($value), $cohort) as $workId) {
                    $result = $this->admissions->admit($workId, $cursor, $count);
                    if (in_array($result->status, [AdmissionStatus::Admitted, AdmissionStatus::CursorMoved, AdmissionStatus::SnapshotChanged], true)) {
                        return $result->status;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Cohorts с eligible work хотя бы одной stage, у которой все scopes enabled, executing handler
     * healthy и child lanes ниже W. Отсутствующие scopes создаёт ensureScopes отдельной tx.
     *
     * @param  list<StageCode>  $stages
     * @param  array<string, LaneLoad>  $loads
     * @return array<string, list<StageCode>> cohort → stages, оба в byte-sorted порядке
     */
    private function eligibleCohorts(array $stages, array $loads): array
    {
        $pairs = $this->admissions->eligiblePairs($stages);
        if ($pairs === []) {
            return [];
        }
        $definitions = [];
        foreach ($pairs as $pair) {
            foreach ($this->policy->workScopes($pair['stage'], $pair['cohort']) as $definition) {
                $definitions[$definition->key] = $definition;
            }
        }
        $modes = $this->controls->modes(array_keys($definitions));
        if (count($modes) !== count($definitions)) {
            $this->transaction->run(fn () => $this->controls->ensureScopes(array_values($definitions)));
            $modes = $this->controls->modes(array_keys($definitions));
        }

        $eligible = [];
        foreach ($pairs as $pair) {
            $code = $pair['stage'];
            $scopes = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $this->policy->workScopes($code, $pair['cohort']));
            $enabled = array_filter($scopes, static fn (string $key): bool => ($modes[$key] ?? ControlMode::Paused) === ControlMode::Enabled);
            $version = $this->registry->handlerVersion($code);
            $healthy = $version !== null && $this->handlers->eligibility($code, $version) === HandlerEligibility::Healthy;
            $room = array_filter(
                $this->policy->requiredLanes($code),
                static fn (string $key): bool => isset($loads[$key]) && $loads[$key]->outstanding < $loads[$key]->maxOutstanding,
            );
            if (count($enabled) === count($scopes) && $healthy && count($room) === count($this->policy->requiredLanes($code))) {
                $eligible[$pair['cohort']][] = $code;
            }
        }
        ksort($eligible, SORT_STRING);

        return $eligible;
    }
}
