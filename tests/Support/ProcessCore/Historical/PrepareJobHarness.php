<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Historical;

use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Jobs\Procurement\PipelineJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Prepare\DocumentPreparer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Стадия подготовки (ТЗ 03 §3.3): `downloaded → prepared` (или терминальный `unprocessable`,
 * D32/D38, поставленный стратегией) / `failed` (error-блок при исключении, D39 — не глотается).
 * Single-shot (`ShouldBeUnique`, P4.1 D39) — защита от двойного диспатча мостом/retry.
 * Владеет диспатчем следующей стадии цепочки: по успеху (не `unprocessable`) — `ScreeningJob`
 * за гейтом `config('ai.screening.enabled', false)` (ТЗ 03 §4).
 */
#[Timeout(2580)]
final class PrepareJobHarness extends PipelineJob implements ShouldBeUnique
{
    private ?Document $documentCache = null;

    public function __construct(
        public readonly int $documentId,
        public readonly RunMode $mode = RunMode::Auto,
        public readonly ?int $initiatedBy = null,
    ) {
        if (! app()->environment('testing') || getenv('PROCESS_CORE_TEST') !== '1') {
            throw new \LogicException('Historical preparation harness is testing-only.');
        }
        $this->onConnection('redis_prepare');
        $this->onQueue('doc_prepare');
    }

    public function uniqueId(): string
    {
        return $this->pipelineUniqueId();
    }

    public function pipelineUniqueId(): string
    {
        return "{$this->documentId}:prepare";
    }

    protected function operationBudgetStage(): string
    {
        return 'prepare';
    }

    protected function statusGuardPassed(): bool
    {
        return $this->document()->status === DocumentStatus::Downloaded;
    }

    protected function acquireLease(): bool
    {
        return $this->document()->acquireLease(owner: self::class, ttlSeconds: $this->leaseSeconds());
    }

    protected function releaseLease(): void
    {
        $this->document()->releaseLease();
    }

    protected function recordFailure(Throwable $exception): void
    {
        $this->document()->recordFailure($exception->getMessage());
    }

    protected function process(): void
    {
        $deadlineAt = $this->executionStartedAt + $this->operationBudgetSeconds();
        $document = $this->document();
        $version = app(DocumentStoreRepository::class)->latestVersion($document->id);

        if ($version === null) {
            throw new RuntimeException("У документа #{$document->id} нет скачанной версии.");
        }

        $result = app(DocumentPreparer::class)->prepare(
            document: $document,
            version: $version,
            deadlineAt: $deadlineAt,
        );

        if ($result === PreparationResult::Unprocessable) {
            return;
        }

        if ($result === PreparationResult::ExpandedContainer) {
            return;
        }

        $screeningEnabled = config('ai.screening.enabled', false);

        $run = DB::transaction(function () use ($document, $version, $screeningEnabled) {
            app(DocumentStoreRepository::class)->update($document, ['status' => DocumentStatus::Prepared]);

            if (! $screeningEnabled) {
                return null;
            }

            return app(AnalysisRunStoreRepository::class)->findOrStartInitial(
                documentVersionId: $version->id,
                mode: $this->mode,
                initiatedBy: $this->initiatedBy,
            );
        });

        if ($run !== null) {
            ScreeningJob::dispatch($document->id)->afterCommit();
        }
    }

    private function document(): Document
    {
        return $this->documentCache ??= Document::findOrFail($this->documentId);
    }

    private function operationBudgetSeconds(): int
    {
        $seconds = config('ai.pipeline.operation_budgets.prepare.job_timeout_seconds');

        if (! is_int($seconds) || $seconds < 1) {
            throw new RuntimeException('ai.pipeline.operation_budgets.prepare.job_timeout_seconds должен быть положительным целым числом.');
        }

        return $seconds;
    }
}
