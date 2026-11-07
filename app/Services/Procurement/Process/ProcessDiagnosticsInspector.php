<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\QueueRoute;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Llm\LlmRoute;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Estimate\Estimate;
use App\Repositories\Procurement\Ai\AnalysisRunReadRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\SupervisorRepository;
use LogicException;

/**
 * Read-only диагностика документов, AI-анализов и смет: проверки собирают признаки зависших
 * стадий, массовых ошибок и backlog, не меняя состояние ledger.
 */
final readonly class ProcessDiagnosticsInspector
{
    /**
     * @return array<string, string>
     */
    public function inspectAll(): array
    {
        $checks = [
            'stuck_screening' => fn (): array => $this->stuckAnalyses(step: AnalysisStep::Screening, configKey: 'analysis_stuck_minutes', findingKey: 'stuck_screening'),
            'stuck_extraction' => fn (): array => $this->stuckAnalyses(step: AnalysisStep::Extraction, configKey: 'checkpoint_stuck_minutes', findingKey: 'stuck_extraction'),
            'stuck_runs' => $this->stuckRuns(...),
            'stuck_documents' => $this->stuckDocuments(...),
            'unknown_spike' => $this->unknownSpike(...),
            'unprocessable_spike' => $this->unprocessableSpike(...),
            'estimate_mismatch_spike' => $this->estimateMismatchSpike(...),
            'model_eol' => $this->eolModels(...),
            'backlog' => $this->backlogSpike(...),
            'failure_rate' => $this->failureRateSpike(...),
            'no_completions' => $this->noCompletions(...),
            'open_incidents' => $this->openIncidents(...),
            'blocked_work' => $this->blockedWork(...),
            'stale_outbox' => $this->staleOutbox(...),
            'stage_queues' => $this->stageQueueCoverage(...),
        ];

        $findings = [];
        foreach ($checks as $key => $check) {
            try {
                $findings = [...$findings, ...$check()];
            } catch (\Throwable $e) {
                $findings[$key] = "Ошибка выполнения диагностической проверки ({$key}): {$e->getMessage()}";
            }
        }

        return $findings;
    }

    /**
     * Анализы в `running` дольше порога при истёкшем лизе: extraction получает СВОЙ,
     * обычно больший порог (`checkpoint_stuck_minutes`) — self-requeue-цепочка легитимно
     * проходит через паузы между checkpoint-прогонами дольше, чем single-shot screening
     * (`analysis_stuck_minutes`); разные конфиг-ключи/finding-ключи не дают двум сигналам
     * схлопнуться в один при совместной агрегации.
     *
     * @return array<string, string>
     */
    public function stuckAnalyses(AnalysisStep $step, string $configKey, string $findingKey): array
    {
        $minutes = $this->intConfig("process.diagnostic_thresholds.{$configKey}", 30);

        $count = DB::table('prc_process_work')->where('stage', $step->value)->where('state', 'running')
            ->whereRaw('lease_until<clock_timestamp()')->whereRaw("updated_at<clock_timestamp()-(? * interval '1 minute')", [$minutes])->count();

        if ($count === 0) {
            return [];
        }

        return [$findingKey => "Зависших анализов ({$step->value}, running >{$minutes}мин, лиз истёк): {$count}"];
    }

    /** @return array<string,string> */
    public function stuckRuns(): array
    {
        $minutes = $this->intConfig('process.diagnostic_thresholds.document_stuck_minutes', 60);

        $count = app(AnalysisRunReadRepository::class)->query()
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('prc_process_work as w')
                ->whereColumn('w.document_version_id', 'prc_analysis_runs.document_version_id')
                ->whereColumn('w.input_revision', 'prc_analysis_runs.expected_analysis_generation')
                ->where('w.stage', 'screening')->whereIn('w.state', ['ready', 'admitted', 'running', 'deferred'])
                ->whereRaw("w.updated_at<clock_timestamp()-(? * interval '1 minute')", [$minutes]))->count();

        if ($count === 0) {
            return [];
        }

        return ['stuck_runs' => "Незакрытых прогонов (>{$minutes}мин): {$count}"];
    }

    /**
     * Документы, застрявшие в discovered/downloaded/prepared/screened сверх порога — разрыв
     * моста между стадиями (screened-затор возможен при выключенном `ai.extraction.enabled` или
     * перманентных фейлах extraction). `discovered` включает зависшее скачивание. `failed_permanently`
     * исключены — dead-letter не подбирается ни этой проверкой, ни `pipeline:retry-failed`.
     *
     * @return array<string, string>
     */
    public function stuckDocuments(): array
    {
        $minutes = $this->intConfig('process.diagnostic_thresholds.document_stuck_minutes', 60);

        $rows = Document::query()
            ->whereIn('status', [DocumentStatus::Discovered->value, DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $breakdown = $rows->map(fn (object $row): string => sprintf(
            '%s=%d',
            $this->stringValue($row->status, 'document status'),
            $this->integerValue($row->total, 'document count'),
        ))->implode(', ');

        return ['stuck_documents' => "Документов застряло на стадии >{$minutes}мин: {$breakdown}"];
    }

    /**
     * Всплеск `unknown`-веток скрининга (`ScreeningOutcomeHandler::branchByType()`) за окно —
     * возможен дрейф классификатора/новый тип документа (ТЗ 03 §5).
     *
     * @return array<string, string>
     */
    public function unknownSpike(): array
    {
        $windowHours = $this->intConfig('process.diagnostic_thresholds.spike_window_hours', 24);
        $threshold = $this->intConfig('process.diagnostic_thresholds.unknown_spike_threshold', 20);

        $count = Analysis::query()
            ->where('step', AnalysisStep::Screening->value)
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->whereRaw("result->'decision'->>'reason' = ?", [ScreeningDecisionReason::UnknownType->value])
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['unknown_spike' => "Всплеск unknown в скрининге за {$windowHours}ч: {$count} (порог {$threshold})"];
    }

    /**
     * Всплеск терминального `unprocessable` за окно — вводная владельца «упёрлись в
     * лимит провайдера → сразу видно в системе».
     *
     * @return array<string, string>
     */
    public function unprocessableSpike(): array
    {
        $windowHours = $this->intConfig('process.diagnostic_thresholds.spike_window_hours', 24);
        $threshold = $this->intConfig('process.diagnostic_thresholds.unprocessable_spike_threshold', 5);

        $count = Document::query()
            ->where('status', DocumentStatus::Unprocessable->value)
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['unprocessable_spike' => "Всплеск unprocessable за {$windowHours}ч: {$count} (порог {$threshold})"];
    }

    /**
     * Расхождение rows_expected/extracted (ТЗ 03 §5) — сметы, детерминированно помеченные
     * `EstimateValidator` как suspicious/invalid, за окно.
     *
     * @return array<string, string>
     */
    public function estimateMismatchSpike(): array
    {
        $windowHours = $this->intConfig('process.diagnostic_thresholds.spike_window_hours', 24);
        $threshold = $this->intConfig('process.diagnostic_thresholds.estimate_mismatch_threshold', 5);

        $count = Estimate::query()
            ->whereIn('validation_status', [EstimateValidationStatus::Suspicious->value, EstimateValidationStatus::Invalid->value])
            ->where('updated_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['estimate_mismatch_spike' => "Расхождение rows_expected/extracted за {$windowHours}ч: {$count} suspicious/invalid смет (порог {$threshold})"];
    }

    /**
     * Модель активного маршрута ближе `config('ai.model_eol_warn_days', 30)` дней к `eol_date`
     * — защита от «модель молча умерла»; отрицательное число дней — модель УЖЕ прошла
     * `eol_date` (условие `<=` покрывает и этот случай).
     *
     * @return array<string, string>
     */
    public function eolModels(): array
    {
        $warnDays = $this->intConfig('ai.model_eol_warn_days', 30);
        $now = CarbonImmutable::now();
        $capabilities = $this->capabilities();

        $models = LlmRoute::query()->where('is_active', true)->distinct()->pluck('model');

        $near = [];

        foreach ($models as $model) {
            if (! is_string($model) || $model === '') {
                continue;
            }

            $eolDate = $capabilities[$model]['eol_date'] ?? null;

            if ($eolDate === null) {
                continue;
            }

            $eol = CarbonImmutable::parse($eolDate);
            $daysLeft = (int) $now->diffInDays($eol, absolute: true);

            if ($eol->isPast()) {
                $daysLeft = -$daysLeft;
            }

            if ($daysLeft <= $warnDays) {
                $successor = $capabilities[$model]['successor'] ?? '?';
                $near[] = "{$model} (осталось {$daysLeft}д, преемник {$successor})";
            }
        }

        if ($near === []) {
            return [];
        }

        return ['model_eol' => 'Модели активных маршрутов близки к EOL: '.implode('; ', $near)];
    }

    /**
     * Backlog — документов одновременно в downloaded/prepared/screened
     * (ждут следующей стадии) БЕЗ временного порога, в отличие от `stuckDocuments()` (там —
     * возраст конкретных документов); здесь — суммарный объём очереди.
     *
     * @return array<string, string>
     */
    public function backlogSpike(): array
    {
        $threshold = $this->intConfig('process.diagnostic_thresholds.backlog_threshold', 200);

        $count = Document::query()
            ->whereIn('status', [DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->count();

        if ($count <= $threshold) {
            return [];
        }

        return ['backlog' => "Backlog документов (downloaded/prepared/screened): {$count} (порог {$threshold})"];
    }

    /**
     * Доля failed среди завершённых анализов (оба шага) за короткое окно
     * — быстрый сигнал массового провала (например, протухший API-ключ), в отличие от
     * `spike_window_hours`-счётчиков (сутки).
     *
     * @return array<string, string>
     */
    public function failureRateSpike(): array
    {
        $windowHours = $this->intConfig('process.diagnostic_thresholds.failure_rate_window_hours', 1);
        $thresholdPercent = $this->floatConfig('process.diagnostic_thresholds.failure_rate_threshold_percent', 50.0);
        $since = now()->subHours($windowHours);

        $counts = DB::table('prc_process_transitions as t')->join('prc_process_work as w', 'w.id', '=', 't.work_id')
            ->where('t.entity_kind', 'work')->whereIn('w.stage', ['screening', 'extraction'])
            ->where('t.occurred_at', '>=', $since)
            ->whereIn('t.new_state', ['succeeded', 'failed_item'])
            ->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE t.new_state = 'failed_item') AS failed")
            ->first();
        $total = $counts !== null ? $this->integerValue($counts->total, 'total transitions') : 0;
        $failed = $counts !== null ? $this->integerValue($counts->failed, 'failed transitions') : 0;

        if ($total === 0) {
            return [];
        }

        $rate = round($failed / $total * 100, 1);

        if ($rate <= $thresholdPercent) {
            return [];
        }

        return ['failure_rate' => "Failure rate анализов за {$windowHours}ч: {$rate}% ({$failed}/{$total}, порог {$thresholdPercent}%)"];
    }

    /**
     * «Нет завершений стадии N минут» — ни одного succeeded-анализа за
     * окно ПРИ НЕПУСТОМ backlog (иначе «нечего обрабатывать» ложно читалось бы как «встал»).
     *
     * @return array<string, string>
     */
    public function noCompletions(): array
    {
        $minutes = $this->intConfig('process.diagnostic_thresholds.no_completions_minutes', 120);

        $hasBacklog = Document::query()
            ->whereIn('status', [DocumentStatus::Downloaded->value, DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->where('failed_permanently', false)
            ->exists();

        if (! $hasBacklog) {
            return [];
        }

        $recentCompletions = DB::table('prc_process_transitions as t')->join('prc_process_work as w', 'w.id', '=', 't.work_id')
            ->where('t.entity_kind', 'work')->whereIn('w.stage', ['screening', 'extraction'])
            ->where('t.new_state', 'succeeded')->where('t.occurred_at', '>=', now()->subMinutes($minutes))
            ->exists();

        if ($recentCompletions) {
            return [];
        }

        return ['no_completions' => "Ни одного завершённого анализа за {$minutes}мин при непустом backlog — пайплайн, похоже, встал"];
    }

    /** @return array<string, string> */
    public function openIncidents(): array
    {
        if (! Schema::hasTable('prc_process_incidents')) {
            return [];
        }

        $count = DB::table('prc_process_incidents')->where('state', 'open')->count();

        if ($count === 0) {
            return [];
        }

        return ['open_incidents' => "Открытых инцидентов (prc_process_incidents): {$count}"];
    }

    /** @return array<string, string> */
    public function blockedWork(): array
    {
        if (! Schema::hasTable('prc_process_work')) {
            return [];
        }

        $count = DB::table('prc_process_work')->where('state', 'blocked')->where('reason_code', '!=', 'paused')->count();

        if ($count === 0) {
            return [];
        }

        return ['blocked_work' => "Заблокированных работ (prc_process_work, кроме paused): {$count}"];
    }

    /** @return array<string, string> */
    public function staleOutbox(): array
    {
        if (! Schema::hasTable('prc_process_outbox')) {
            return [];
        }

        $count = DB::table('prc_process_outbox')
            ->whereNull('published_at')
            ->where('available_at', '<', now()->subMinutes(15))
            ->count();

        if ($count === 0) {
            return [];
        }

        return ['stale_outbox' => "Неопубликованных сообщений в outbox (>15мин): {$count}"];
    }

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

    /**
     * @return array<string, array{
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
    public function inspectStageQueues(): array
    {
        if (! Schema::hasTable('prc_process_work')) {
            return [];
        }

        $routes = app(StageQueueMap::class)->routes();
        $policy = app(ProcessPolicy::class);
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
            $stage = $this->stringValue($row->stage, 'work stage');
            $cohort = $this->stringValue($row->cohort_key, 'work cohort_key');
            $dueCount = $this->integerValue($row->due_count, 'due work count');
            $pausedWorkCount = $this->integerValue($row->paused_count, 'paused work count');
            $oldestDueAge = max(0, $this->signedIntegerValue($row->oldest_due_age_seconds, 'oldest due work age'));

            $stageCode = StageCode::tryFrom($stage);
            $isCohortPaused = $stageCode !== null && $this->isWorkCohortPaused($policy, $stageCode, $cohort, $pausedScopeSet, $heldStageSet);

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
            $isStagePaused = $this->isStagePaused($policy, $route->stage, $pausedScopeSet, $heldStageSet);
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
        if (! app()->bound(SupervisorRepository::class)) {
            return [];
        }

        $supervisors = [];
        foreach (app(SupervisorRepository::class)->all() as $item) {
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
    private function isStagePaused(ProcessPolicy $policy, StageCode $stageEnum, array $pausedScopes, array $heldStages): bool
    {
        $stageKey = $stageEnum->value;
        if (isset($heldStages[$stageKey])) {
            return true;
        }

        foreach ($policy->stageScopes($stageEnum) as $scope) {
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
    private function isWorkCohortPaused(ProcessPolicy $policy, StageCode $stageEnum, string $cohort, array $pausedScopes, array $heldStages): bool
    {
        $stageKey = $stageEnum->value;
        if (isset($heldStages[$stageKey])) {
            return true;
        }

        foreach ($policy->workScopes($stageEnum, $cohort) as $scope) {
            if (isset($pausedScopes[$scope->key])) {
                return true;
            }
        }

        return false;
    }

    private function stageStuckMinutes(StageCode $stageEnum): int
    {
        $defaultMinutes = $this->intConfig('process.diagnostic_thresholds.document_stuck_minutes', 60);
        $thresholds = [
            'screening' => $this->intConfig('process.diagnostic_thresholds.analysis_stuck_minutes', 30),
            'extraction' => $this->intConfig('process.diagnostic_thresholds.checkpoint_stuck_minutes', $defaultMinutes),
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

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new LogicException("{$key} должен быть целым числом.");
        }

        return $value;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        if (! is_float($value) && ! is_int($value)) {
            throw new LogicException("{$key} должен быть числом.");
        }

        return (float) $value;
    }

    private function integerValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException("{$context} должен быть целым числом.");
    }

    private function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new LogicException("{$context} должен быть строкой.");
        }

        return $value;
    }

    /**
     * @return array<string, array{eol_date?: string, successor?: string}>
     */
    private function capabilities(): array
    {
        $value = config('ai.capabilities', []);

        if (! is_array($value)) {
            throw new LogicException('ai.capabilities должен быть массивом моделей.');
        }

        $capabilities = [];

        foreach ($value as $model => $attributes) {
            if (! is_string($model) || ! is_array($attributes)) {
                throw new LogicException('Каждая capability должна иметь строковый код модели и массив атрибутов.');
            }

            $capability = [];
            $eolDate = $attributes['eol_date'] ?? null;
            $successor = $attributes['successor'] ?? null;

            if ($eolDate !== null) {
                $capability['eol_date'] = $this->stringValue($eolDate, "eol_date модели {$model}");
            }

            if ($successor !== null) {
                $capability['successor'] = $this->stringValue($successor, "successor модели {$model}");
            }

            $capabilities[$model] = $capability;
        }

        return $capabilities;
    }
}
