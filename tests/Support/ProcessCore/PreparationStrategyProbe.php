<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\PreparationResult;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Prepare\DocumentPreparer;

/** Legacy FORMAT corpus only. Fixture status models the next strategy input; no job/lease/retry/AI. */
final readonly class PreparationStrategyProbe
{
    public function __construct(private int $documentId) {}

    public function handle(): void
    {
        $document = Document::query()->findOrFail($this->documentId);
        if ($document->status !== DocumentStatus::Downloaded) {
            return;
        }
        $version = app(DocumentStoreRepository::class)->latestVersion($this->documentId);
        if ($version === null) {
            throw new \LogicException('Missing format fixture version.');
        }
        $seconds = config('ai.pipeline.operation_budgets.prepare.job_timeout_seconds');
        if (! is_int($seconds)) {
            throw new \LogicException('Missing bounded format probe profile.');
        }
        $result = app(DocumentPreparer::class)->prepare($document, $version, microtime(true) + $seconds);
        if ($result === PreparationResult::ReadyForScreening) {
            $document->update(['status' => DocumentStatus::Prepared]);
        }
    }
}
