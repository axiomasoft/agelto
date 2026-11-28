<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Diagnostics;

use App\Enums\Procurement\Ai\AnalysisStep;

/** @phpstan-import-type StageQueueReport from StageQueueInspector */
final readonly class ProcessDiagnosticsInspector
{
    public function __construct(
        private PipelineDiagnosticsInspector $pipeline,
        private ProcessLedgerInspector $ledger,
        private StageQueueInspector $stageQueues,
    ) {}

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

    /** @return array<string, string> */
    public function stuckAnalyses(AnalysisStep $step, string $configKey, string $findingKey): array
    {
        return $this->pipeline->stuckAnalyses(step: $step, configKey: $configKey, findingKey: $findingKey);
    }

    /** @return array<string, string> */
    public function stuckRuns(): array
    {
        return $this->pipeline->stuckRuns();
    }

    /** @return array<string, string> */
    public function stuckDocuments(): array
    {
        return $this->pipeline->stuckDocuments();
    }

    /** @return array<string, string> */
    public function unknownSpike(): array
    {
        return $this->pipeline->unknownSpike();
    }

    /** @return array<string, string> */
    public function unprocessableSpike(): array
    {
        return $this->pipeline->unprocessableSpike();
    }

    /** @return array<string, string> */
    public function estimateMismatchSpike(): array
    {
        return $this->pipeline->estimateMismatchSpike();
    }

    /** @return array<string, string> */
    public function eolModels(): array
    {
        return $this->pipeline->eolModels();
    }

    /** @return array<string, string> */
    public function backlogSpike(): array
    {
        return $this->pipeline->backlogSpike();
    }

    /** @return array<string, string> */
    public function failureRateSpike(): array
    {
        return $this->pipeline->failureRateSpike();
    }

    /** @return array<string, string> */
    public function noCompletions(): array
    {
        return $this->pipeline->noCompletions();
    }

    /** @return array<string, string> */
    public function openIncidents(): array
    {
        return $this->ledger->openIncidents();
    }

    /** @return array<string, string> */
    public function blockedWork(): array
    {
        return $this->ledger->blockedWork();
    }

    /** @return array<string, string> */
    public function staleOutbox(): array
    {
        return $this->ledger->staleOutbox();
    }

    /** @return array<string, string> */
    public function stageQueueCoverage(): array
    {
        return $this->stageQueues->stageQueueCoverage();
    }

    /** @return StageQueueReport */
    public function inspectStageQueues(): array
    {
        return $this->stageQueues->inspectStageQueues();
    }
}
