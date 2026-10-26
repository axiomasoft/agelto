<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\CohortKey;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\LanePolicy;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Enums\Procurement\Process\ScopeKind;
use App\Enums\Procurement\Process\StageCode;
use Illuminate\Contracts\Config\Repository;

/**
 * Boot-validated process policy v1 из `config/process.php`. Конечные typed таблицы
 * required_lanes(stage) и control scope stage (execution#s11): parent/child lanes действуют
 * одновременно; acquisition и documents controls раздельны. Неполная или противоречивая
 * конфигурация отвергается с ключом config, а не заменяется default.
 */
final readonly class ProcessPolicy
{
    /** @var array<string, list<string>> stage → required lane keys, parent перед child */
    private const array REQUIRED_LANES = [
        'listing' => ['acquisition', 'acquisition.listing'],
        'detail' => ['acquisition', 'acquisition.detail'],
        'status_refresh' => ['acquisition', 'acquisition.status_refresh'],
        'download' => ['download'],
        'prepare' => ['prepare'],
        'screening' => ['analysis', 'analysis.screening'],
        'extraction' => ['analysis', 'analysis.extraction'],
        'publication' => ['publication'],
    ];

    /** @var array<string, ScopeKind> stage → purpose root его control scope */
    private const array STAGE_PURPOSES = [
        'listing' => ScopeKind::Acquisition,
        'detail' => ScopeKind::Acquisition,
        'status_refresh' => ScopeKind::Acquisition,
        'download' => ScopeKind::Documents,
        'prepare' => ScopeKind::Documents,
        'screening' => ScopeKind::Documents,
        'extraction' => ScopeKind::Documents,
        'publication' => ScopeKind::Documents,
    ];

    public int $policyVersion;

    public int $maxHandlerFaultsPerWorkVersion;

    /** Work старше этого возраста по immutable created_at идёт первым внутри stage (age900). */
    public int $ageSeconds;

    /** Bounded число candidates внутри уже выбранных cohort/stage (LIMIT после RR, не до). */
    public int $candidateLimit;

    public int $capacityWaitMinMs;

    public int $capacityWaitMaxMs;

    /** Минимальный monotonic интервал между heartbeat CAS одного attempt. */
    public int $heartbeatSeconds;

    /** Резерв на finalize, который canStart прибавляет к каждому effect. */
    public int $finalizationReserveSeconds;

    /** Локальный safety deadline = последний renewal + lease − этот запас. */
    public int $leaseSafetySeconds;

    /** @var array<string, LanePolicy> */
    private array $lanes;

    /** @var array<string, array{claim_visibility: int, slice: int, lease: int}> */
    private array $stageTimings;

    public function __construct(Repository $config)
    {
        $this->policyVersion = self::positiveInteger($config->get('process.policy_version'), 'process.policy_version');
        $this->maxHandlerFaultsPerWorkVersion = self::positiveInteger(
            $config->get('process.max_handler_faults_per_work_version'),
            'process.max_handler_faults_per_work_version',
        );
        $this->lanes = self::lanePolicies($config->get('process.lanes'));
        $this->ageSeconds = self::positiveInteger($config->get('process.admission.age_seconds'), 'process.admission.age_seconds');
        $this->candidateLimit = self::positiveInteger($config->get('process.admission.candidate_limit'), 'process.admission.candidate_limit');
        $this->capacityWaitMinMs = self::positiveInteger($config->get('process.admission.capacity_wait_min_ms'), 'process.admission.capacity_wait_min_ms');
        $this->capacityWaitMaxMs = self::positiveInteger($config->get('process.admission.capacity_wait_max_ms'), 'process.admission.capacity_wait_max_ms');
        if ($this->capacityWaitMaxMs < $this->capacityWaitMinMs) {
            throw new \InvalidArgumentException('Invalid process policy: process.admission.capacity_wait_max_ms');
        }
        $this->heartbeatSeconds = self::positiveInteger($config->get('process.execution.heartbeat_seconds'), 'process.execution.heartbeat_seconds');
        $this->finalizationReserveSeconds = self::positiveInteger($config->get('process.execution.finalization_reserve_seconds'), 'process.execution.finalization_reserve_seconds');
        $this->leaseSafetySeconds = self::positiveInteger($config->get('process.execution.lease_safety_seconds'), 'process.execution.lease_safety_seconds');
        $execution = $config->get('process.execution');
        if (! is_array($execution) || array_keys($execution) !== ['heartbeat_seconds', 'finalization_reserve_seconds', 'lease_safety_seconds']) {
            throw new \InvalidArgumentException('Invalid process policy: process.execution');
        }
        $this->stageTimings = self::stageTimings($config->get('process.stages'), 2 * $this->heartbeatSeconds + $this->leaseSafetySeconds);
    }

    /** Monotonic slice одного attempt: после него handler выходит checkpoint/partial. */
    public function sliceSeconds(StageCode $stage): int
    {
        return $this->stageTimings[$stage->value]['slice'];
    }

    /** Delivery deadline admitted work: transport bound, не execution lease. */
    public function claimVisibilitySeconds(StageCode $stage): int
    {
        return $this->stageTimings[$stage->value]['claim_visibility'];
    }

    /** DB lease claimed attempt; решения истечения принимает PostgreSQL clock. */
    public function leaseSeconds(StageCode $stage): int
    {
        return $this->stageTimings[$stage->value]['lease'];
    }

    /** Parent lane stage: общий fairness cursor и W/C всех sibling stages. */
    public function parentLane(StageCode $stage): string
    {
        return self::REQUIRED_LANES[$stage->value][0];
    }

    /**
     * Sibling stages общего parent lane в стабильном порядке значений.
     *
     * @return list<StageCode>
     */
    public function stagesOfParentLane(string $parentLane): array
    {
        $stages = [];
        foreach (self::REQUIRED_LANES as $value => $required) {
            if ($required[0] === $parentLane) {
                $stages[] = StageCode::from($value);
            }
        }
        usort($stages, static fn (StageCode $a, StageCode $b): int => strcmp($a->value, $b->value));

        return $stages;
    }

    /** Hash точного required lane set: membership admission group сверяется с ним под locks. */
    public function requiredLaneSetHash(StageCode $stage): ContentHash
    {
        $keys = self::REQUIRED_LANES[$stage->value];
        sort($keys, SORT_STRING);

        return ContentHash::of('required-lanes:v1:'.implode(',', $keys));
    }

    /**
     * Полная control closure work: цепочка stage плюс scope её cohort (platform либо manual) под
     * тем же purpose root. Admission не создаёт scopes: отсутствующие создаёт отдельная ensureScopes.
     *
     * @return list<ScopeDefinition>
     */
    public function workScopes(StageCode $stage, string $cohort): array
    {
        $purpose = self::STAGE_PURPOSES[$stage->value]->value;
        $platform = CohortKey::platformCode($cohort);
        $cohortScope = $platform === null
            ? new ScopeDefinition(ScopeKind::Cohort, $purpose.':cohort:'.CohortKey::MANUAL, $purpose)
            : new ScopeDefinition(ScopeKind::Platform, $purpose.':platform:'.$platform, $purpose);

        return [...$this->stageScopes($stage), $cohortScope];
    }

    /** @return list<LanePolicy> parent перед child, стабильный порядок ключей */
    public function lanes(): array
    {
        $lanes = array_values($this->lanes);
        usort($lanes, static fn (LanePolicy $a, LanePolicy $b): int => [$a->parentKey !== null, $a->key] <=> [$b->parentKey !== null, $b->key]);

        return $lanes;
    }

    /** @return list<string> */
    public function requiredLanes(StageCode $stage): array
    {
        return self::REQUIRED_LANES[$stage->value];
    }

    /**
     * Полная цепочка scopes stage: system → purpose root → stage scope.
     *
     * @return list<ScopeDefinition>
     */
    public function stageScopes(StageCode $stage): array
    {
        $purpose = self::STAGE_PURPOSES[$stage->value];

        return [
            new ScopeDefinition(ScopeKind::System, ScopeKind::System->value, null),
            new ScopeDefinition($purpose, $purpose->value, ScopeKind::System->value),
            new ScopeDefinition(ScopeKind::Stage, $purpose->value.':stage:'.$stage->value, $purpose->value),
        ];
    }

    /** @return array<string, LanePolicy> */
    private static function lanePolicies(mixed $lanes): array
    {
        if (! is_array($lanes) || $lanes === []) {
            throw new \InvalidArgumentException('Invalid process policy: process.lanes');
        }
        $policies = [];
        foreach ($lanes as $key => $lane) {
            $path = 'process.lanes.'.$key;
            if (! is_string($key) || ! is_array($lane) || array_keys($lane) !== ['parent', 'max_outstanding', 'max_running', 'low_watermark', 'refill_max']
                || ! (is_string($lane['parent']) || $lane['parent'] === null)) {
                throw new \InvalidArgumentException('Invalid process policy: '.$path);
            }
            try {
                $policies[$key] = new LanePolicy(
                    key: $key,
                    parentKey: $lane['parent'],
                    maxOutstanding: self::positiveInteger($lane['max_outstanding'], $path.'.max_outstanding'),
                    maxRunning: self::positiveInteger($lane['max_running'], $path.'.max_running'),
                    lowWatermark: self::integer($lane['low_watermark'], $path.'.low_watermark'),
                    refillMax: self::positiveInteger($lane['refill_max'], $path.'.refill_max'),
                );
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('Invalid process policy: '.$path, previous: $exception);
            }
        }
        foreach ($policies as $policy) {
            $parent = $policy->parentKey === null ? null : ($policies[$policy->parentKey] ?? null);
            if ($policy->parentKey !== null && ($parent === null || $parent->parentKey !== null)) {
                throw new \InvalidArgumentException('Invalid process policy: process.lanes.'.$policy->key.'.parent');
            }
        }
        foreach (self::REQUIRED_LANES as $required) {
            foreach ($required as $key) {
                if (! isset($policies[$key])) {
                    throw new \InvalidArgumentException('Invalid process policy: process.lanes.'.$key);
                }
            }
        }

        return $policies;
    }

    /**
     * Lease обязан пережить slice и кооперативный heartbeat с safety запасом (bp-heartbeat).
     *
     * @return array<string, array{claim_visibility: int, slice: int, lease: int}>
     */
    private static function stageTimings(mixed $stages, int $minimumLease): array
    {
        if (! is_array($stages)) {
            throw new \InvalidArgumentException('Invalid process policy: process.stages');
        }
        $timings = [];
        foreach (StageCode::cases() as $code) {
            $path = 'process.stages.'.$code->value;
            $timing = $stages[$code->value] ?? null;
            if (! is_array($timing) || array_keys($timing) !== ['claim_visibility', 'slice', 'lease']) {
                throw new \InvalidArgumentException('Invalid process policy: '.$path);
            }
            $timings[$code->value] = [
                'claim_visibility' => self::positiveInteger($timing['claim_visibility'], $path.'.claim_visibility'),
                'slice' => self::positiveInteger($timing['slice'], $path.'.slice'),
                'lease' => self::positiveInteger($timing['lease'], $path.'.lease'),
            ];
            if ($timings[$code->value]['lease'] <= max($timings[$code->value]['slice'], $minimumLease)) {
                throw new \InvalidArgumentException('Invalid process policy: '.$path.'.lease');
            }
        }
        if (count($stages) !== count($timings)) {
            throw new \InvalidArgumentException('Invalid process policy: process.stages');
        }

        return $timings;
    }

    private static function positiveInteger(mixed $value, string $path): int
    {
        $integer = self::integer($value, $path);
        if ($integer < 1) {
            throw new \InvalidArgumentException('Invalid process policy: '.$path);
        }

        return $integer;
    }

    private static function integer(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('Invalid process policy: '.$path);
        }

        return $value;
    }
}
