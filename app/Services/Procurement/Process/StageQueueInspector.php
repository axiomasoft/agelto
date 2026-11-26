<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\QueueRoute;
use App\Enums\Procurement\Process\StageCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\SupervisorRepository;
use LogicException;

/**
 * @phpstan-type StageQueueReport array<string, array{
 *     stage: string,
 *     connection: string,
 *     queue: string,
 *     status: 'no_work'|'paused'|'active_backlog'|'unconsumed_queue'|'stalled_progress',
 *     has_active_consumer: bool,
 *     processes: int,
 *     eligible_backlog: int,
 *     paused_count: int,
 *     oldest_eligible_age_seconds: int,
 * }>
 */
final readonly class StageQueueInspector
{
    public function __construct(
        private StageQueueMap $queueMap,
        private ProcessPolicy $policy,
        private ?SupervisorRepository $supervisorRepository = null,
    ) {}

    /**
     * Проверяет покрытие всех 8 стадий активными supervisor-ами Horizon (по паре connection+queue
     * из StageQueueMap) и отсутствие зависания прогресса (stalled_progress) по часам БД.
     *
     * @return array<string, string>
     */
    public function stageQueueCoverage(): array
    {
        if (! Schema::hasTable('prc_process_work')) {
            return [];
        }

        $supervisors = $this->activeSupervisors();
        $report = $this->inspectStageQueues();
        $findings = [];

        foreach ($report as $stage => $entry) {
            if ($supervisors !== [] && ! $entry['has_active_consumer']) {
                $findings['stage_queue_'.$stage] = "Очередь стадии {$stage} ({$entry['connection']}:{$entry['queue']}) не обслуживается активным supervisor Horizon (eligible backlog={$entry['eligible_backlog']}, oldest={$entry['oldest_eligible_age_seconds']}с)";
            }

            if ($entry['status'] === 'stalled_progress') {
                $findings['stage_stalled_'.$stage] = "Стадия {$stage} ({$entry['connection']}:{$entry['queue']}) не продвигается: eligible backlog={$entry['eligible_backlog']}, oldest={$entry['oldest_eligible_age_seconds']}с";
            }
        }

        return $findings;
    }

    /** @return StageQueueReport */
    public function inspectStageQueues(): array
    {
        if (! Schema::hasTable('prc_process_work')) {
            return [];
        }

        $routes = $this->queueMap->routes();
        $supervisors = $this->activeSupervisors();
        $pausedScopeSet = $this->pausedControlScopes();
        $heldStageSet = $this->heldHandlerStages();

        /** @var array<string, int> $eligibleByStage */
        $eligibleByStage = [];
        /** @var array<string, int> $pausedByStage */
        $pausedByStage = [];
        /** @var array<string, int> $oldestAgeByStage */
        $oldestAgeByStage = [];

        $workRows = DB::table('prc_process_work as w')
            ->selectRaw("
                w.stage,
                w.cohort_key,
                COUNT(*) FILTER (
                    WHERE (
                        w.state IN ('ready', 'admitted')
                        OR (w.state = 'deferred' AND w.due_at <= clock_timestamp())
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM prc_operation_intents i
                        WHERE i.id = w.intent_id AND i.state = 'pending'
                    )
                ) AS due_count,
                COUNT(*) FILTER (
                    WHERE w.state = 'blocked' AND w.reason_code = 'paused'
                ) AS paused_count,
                COALESCE(
                    MAX(
                        EXTRACT(EPOCH FROM (clock_timestamp() - LEAST(w.created_at, w.updated_at)))
                    ) FILTER (
                        WHERE (
                            w.state IN ('ready', 'admitted')
                            OR (w.state = 'deferred' AND w.due_at <= clock_timestamp())
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM prc_operation_intents i
                            WHERE i.id = w.intent_id AND i.state = 'pending'
                        )
                    )::int,
                    0
                ) AS oldest_due_age_seconds
            ")
            ->groupBy('w.stage', 'w.cohort_key')
            ->get();

        foreach ($workRows as $row) {
            $stage = DiagnosticValues::stringValue($row->stage, 'work stage');
            $cohort = DiagnosticValues::stringValue($row->cohort_key, 'work cohort_key');
            $dueCount = DiagnosticValues::integerValue($row->due_count, 'due work count');
            $pausedWorkCount = DiagnosticValues::integerValue($row->paused_count, 'paused work count');
            $oldestDueAge = max(0, $this->signedIntegerValue($row->oldest_due_age_seconds, 'oldest due work age'));

            $stageCode = StageCode::tryFrom($stage);
            $isCohortPaused = $stageCode !== null && $this->isWorkCohortPaused($stageCode, $cohort, $pausedScopeSet, $heldStageSet);

            if ($isCohortPaused) {
                $pausedByStage[$stage] = ($pausedByStage[$stage] ?? 0) + $dueCount + $pausedWorkCount;

                continue;
            }

            $eligibleByStage[$stage] = ($eligibleByStage[$stage] ?? 0) + $dueCount;
            $pausedByStage[$stage] = ($pausedByStage[$stage] ?? 0) + $pausedWorkCount;
            if ($dueCount > 0) {
                $oldestAgeByStage[$stage] = max($oldestAgeByStage[$stage] ?? 0, $oldestDueAge);
            }
        }

        $report = [];
        foreach ($routes as $route) {
            $stage = $route->stage->value;
            $processes = $this->routeProcessCount($supervisors, $route);
            $hasActiveConsumer = $processes > 0;
            $eligibleBacklog = $eligibleByStage[$stage] ?? 0;
            $pausedCount = $pausedByStage[$stage] ?? 0;
            $oldestEligibleAgeSeconds = $eligibleBacklog > 0 ? ($oldestAgeByStage[$stage] ?? 0) : 0;
            $isStagePaused = $this->isStagePaused($route->stage, $pausedScopeSet, $heldStageSet);
            $stuckMinutes = $this->stageStuckMinutes($route->stage);
            $isStalled = $eligibleBacklog > 0
                && $oldestEligibleAgeSeconds >= $stuckMinutes * 60
                && ! $this->hasRecentStageCompletion($stage, $stuckMinutes);

            if ($eligibleBacklog === 0) {
                $status = ($pausedCount > 0 || $isStagePaused) ? 'paused' : 'no_work';
            } elseif (! $hasActiveConsumer && ($supervisors !== [] || ! $isStalled)) {
                $status = 'unconsumed_queue';
            } elseif ($isStalled) {
                $status = 'stalled_progress';
            } else {
                $status = 'active_backlog';
            }

            $report[$stage] = [
                'stage' => $stage,
                'connection' => $route->connection,
                'queue' => $route->queue,
                'status' => $status,
                'has_active_consumer' => $hasActiveConsumer,
                'processes' => $processes,
                'eligible_backlog' => $eligibleBacklog,
                'paused_count' => $pausedCount,
                'oldest_eligible_age_seconds' => $oldestEligibleAgeSeconds,
            ];
        }

        return $report;
    }

    /**
     * @return list<object>
     */
    private function activeSupervisors(): array
    {
        if ($this->supervisorRepository === null) {
            return [];
        }

        $supervisors = [];
        foreach ($this->supervisorRepository->all() as $item) {
            if (is_object($item)) {
                $supervisors[] = $item;
            }
        }

        return $supervisors;
    }

    /**
     * @param  list<object>  $supervisors
     */
    private function routeProcessCount(array $supervisors, QueueRoute $route): int
    {
        $compoundKey = "{$route->connection}:{$route->queue}";
        $total = 0;

        foreach ($supervisors as $supervisor) {
            $vars = get_object_vars($supervisor);
            if (($vars['status'] ?? null) !== 'running') {
                continue;
            }

            $options = is_array($vars['options'] ?? null) ? $vars['options'] : [];
            $connection = $options['connection'] ?? null;
            if (is_string($connection) && $connection !== '' && $connection !== $route->connection) {
                continue;
            }

            $processes = is_array($vars['processes'] ?? null) ? $vars['processes'] : [];
            if (array_key_exists($compoundKey, $processes) && is_numeric($processes[$compoundKey])) {
                $total += max(0, (int) $processes[$compoundKey]);

                continue;
            }

            if ($connection === $route->connection && array_key_exists($route->queue, $processes) && is_numeric($processes[$route->queue])) {
                $total += max(0, (int) $processes[$route->queue]);
            }
        }

        return $total;
    }

    /**
     * @return array<string, true>
     */
    private function pausedControlScopes(): array
    {
        if (! Schema::hasTable('prc_control_scopes')) {
            return [];
        }

        $scopes = [];
        foreach (DB::table('prc_control_scopes')->where('desired_mode', 'paused')->pluck('scope_key') as $key) {
            if (is_string($key) && $key !== '') {
                $scopes[$key] = true;
            }
        }

        return $scopes;
    }

    /**
     * @return array<string, true>
     */
    private function heldHandlerStages(): array
    {
        if (! Schema::hasTable('prc_process_handler_holds')) {
            return [];
        }

        $held = [];
        foreach (DB::table('prc_process_handler_holds')->where('state', 'held')->pluck('stage') as $stageKey) {
            if (is_string($stageKey) && $stageKey !== '') {
                $held[$stageKey] = true;
            }
        }

        return $held;
    }

    /**
     * @param  array<string, true>  $pausedScopes
     * @param  array<string, true>  $heldStages
     */
    private function isStagePaused(StageCode $stageEnum, array $pausedScopes, array $heldStages): bool
    {
        $stageKey = $stageEnum->value;
        if (isset($heldStages[$stageKey])) {
            return true;
        }

        foreach ($this->policy->stageScopes($stageEnum) as $scope) {
            if (isset($pausedScopes[$scope->key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $pausedScopes
     * @param  array<string, true>  $heldStages
     */
    private function isWorkCohortPaused(StageCode $stageEnum, string $cohort, array $pausedScopes, array $heldStages): bool
    {
        $stageKey = $stageEnum->value;
        if (isset($heldStages[$stageKey])) {
            return true;
        }

        foreach ($this->policy->workScopes($stageEnum, $cohort) as $scope) {
            if (isset($pausedScopes[$scope->key])) {
                return true;
            }
        }

        return false;
    }

    private function stageStuckMinutes(StageCode $stageEnum): int
    {
        $defaultMinutes = DiagnosticValues::intConfig('process.diagnostic_thresholds.document_stuck_minutes', 60);
        $thresholds = [
            'screening' => DiagnosticValues::intConfig('process.diagnostic_thresholds.analysis_stuck_minutes', 30),
            'extraction' => DiagnosticValues::intConfig('process.diagnostic_thresholds.checkpoint_stuck_minutes', $defaultMinutes),
        ];

        return $thresholds[$stageEnum->value] ?? $defaultMinutes;
    }

    private function hasRecentStageCompletion(string $stage, int $minutes): bool
    {
        if (! Schema::hasTable('prc_process_transitions')) {
            return false;
        }

        return DB::table('prc_process_transitions as t')
            ->join('prc_process_work as w', 'w.id', '=', 't.work_id')
            ->where('t.entity_kind', 'work')
            ->where('w.stage', $stage)
            ->where('t.new_state', 'succeeded')
            ->whereRaw("t.occurred_at >= clock_timestamp() - (? * interval '1 minute')", [$minutes])
            ->exists();
    }

    private function signedIntegerValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException("{$context} должен быть целым числом.");
    }
}
