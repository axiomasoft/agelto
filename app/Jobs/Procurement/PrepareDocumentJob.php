<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Services\Procurement\Prepare\DocumentPreparer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use RuntimeException;
use Throwable;

/**
 * Стадия подготовки (ТЗ 03 §3.3): `downloaded → prepared` (или терминальный `unprocessable`,
 * D32/D38, поставленный стратегией) / `failed` (error-блок при исключении, D39 — не глотается).
 * Single-shot (`ShouldBeUnique`, P4.1 D39) — защита от двойного диспатча мостом/retry.
 * Владеет диспатчем следующей стадии цепочки: по успеху (не `unprocessable`) — `ScreeningJob`
 * за гейтом `config('ai.screening.enabled', false)` (ТЗ 03 §4).
 */
final class PrepareDocumentJob extends PipelineJob implements ShouldBeUnique
{
    private ?Document $documentCache = null;

    public function __construct(
        public readonly int $documentId,
        public readonly RunMode $mode = RunMode::Auto,
        public readonly ?int $initiatedBy = null,
    ) {
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

    protected function statusGuardPassed(): bool
    {
        return $this->document()->status === DocumentStatus::Downloaded;
    }

    protected function acquireLease(): bool
    {
        return $this->document()->acquireLease(owner: self::class);
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
        $document = $this->document();
        $version = app(DocumentStoreRepository::class)->latestVersion($document->id);

        if ($version === null) {
            throw new RuntimeException("У документа #{$document->id} нет скачанной версии.");
        }

        app(DocumentPreparer::class)->prepare($document, $version);

        $document->refresh();

        if ($document->status === DocumentStatus::Unprocessable) {
            return;
        }

        app(DocumentStoreRepository::class)->update($document, ['status' => DocumentStatus::Prepared]);

        if (config('ai.screening.enabled', false)) {
            // Launch-точка (§3.5 root/document-contour.md) — режим и инициатор едут параметрами
            // конструктора (D135): боевой конвейер (`RunMode::Auto` по умолчанию) и UI/harness
            // launch-точки (P5.2, `RunMode::Manual` + инициатор) заводят прогон здесь одинаково.
            app(AnalysisRunStoreRepository::class)->start($version->id, $this->mode, $this->initiatedBy);

            ScreeningJob::dispatch($document->id)->afterCommit();
        }
    }

    private function document(): Document
    {
        return $this->documentCache ??= Document::findOrFail($this->documentId);
    }
}
