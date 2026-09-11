<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;

/** Recovers durable pipeline state after a queue dispatch is lost or an AI retry becomes due. */
final readonly class PipelineRecoveryReconciler
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private DocumentStoreRepository $documents,
        private DocumentAnalyzerRegistry $analyzers,
    ) {}

    public function reconcile(int $limit): int
    {
        if ($limit <= 0) {
            throw new \UnexpectedValueException('The recovery limit must be a positive integer.');
        }

        $dispatched = 0;

        foreach ($this->candidates($limit) as $document) {
            $version = $this->documents->latestVersion($document->id);

            if ($version === null) {
                continue;
            }

            $run = $this->runs->findActive($version->id);

            if ($run === null) {
                continue;
            }

            $dispatched += match ($document->status) {
                DocumentStatus::Prepared => $this->recoverScreening(document: $document, versionId: $version->id, run: $run) ? 1 : 0,
                DocumentStatus::Screened => $this->recoverExtraction(document: $document, versionId: $version->id, run: $run) ? 1 : 0,
                default => 0,
            };

            if ($dispatched >= $limit) {
                break;
            }
        }

        return $dispatched;
    }

    /** @return iterable<Document> */
    private function candidates(int $limit): iterable
    {
        return Document::query()
            ->whereIn('status', [DocumentStatus::Prepared->value, DocumentStatus::Screened->value])
            ->whereNull('removed_at')
            ->lazyById(chunkSize: $limit);
    }

    private function recoverScreening(Document $document, int $versionId, AnalysisRun $run): bool
    {
        if (! $this->featureEnabled('ai.screening.enabled')) {
            return false;
        }

        $analysis = $this->analyses->findActive(documentVersionId: $versionId, step: AnalysisStep::Screening)
            ?? $this->analyses->firstOrCreate(documentVersionId: $versionId, step: AnalysisStep::Screening);

        if (! $this->isRecoverable(analysis: $analysis, run: $run)) {
            return false;
        }

        if (! $this->analyses->claimRecovery(analysis: $analysis, holdMinutes: $this->claimHoldMinutes())) {
            return false;
        }

        ScreeningJob::dispatch(documentId: $document->id)->afterCommit();

        return true;
    }

    private function recoverExtraction(Document $document, int $versionId, AnalysisRun $run): bool
    {
        if (! $this->featureEnabled('ai.extraction.enabled')) {
            return false;
        }

        $screening = $this->analyses->findActive(documentVersionId: $versionId, step: AnalysisStep::Screening);

        if ($screening === null || $screening->analysis_run_id !== $run->id || ! $this->extracts($screening)) {
            return false;
        }

        $documentType = AiDocumentType::tryFrom((string) $screening->document_type);
        $analyzer = $documentType === null ? null : $this->analyzers->for($documentType);

        if ($analyzer?->hasExtraction() !== true) {
            return false;
        }

        $analysis = $this->analyses->findActive(documentVersionId: $versionId, step: AnalysisStep::Extraction)
            ?? $this->analyses->firstOrCreate(documentVersionId: $versionId, step: AnalysisStep::Extraction);

        if (! $this->isRecoverable(analysis: $analysis, run: $run)) {
            return false;
        }

        if (! $this->analyses->claimRecovery(analysis: $analysis, holdMinutes: $this->claimHoldMinutes())) {
            return false;
        }

        $analyzer->dispatchExtraction(documentId: $document->id);

        return true;
    }

    private function isRecoverable(Analysis $analysis, AnalysisRun $run): bool
    {
        return $analysis->analysis_run_id === $run->id
            && ! $analysis->is_shadow
            && ! $analysis->failed_permanently
            && in_array($analysis->status, [AnalysisStatus::Pending, AnalysisStatus::Running], true);
    }

    private function extracts(Analysis $analysis): bool
    {
        $result = $analysis->result?->all();

        return $analysis->status === AnalysisStatus::Succeeded
            && is_array($result)
            && data_get($result, 'decision.disposition') === 'extract'
            && data_get($result, 'decision.extraction_dispatched') === true;
    }

    private function claimHoldMinutes(): int
    {
        $minutes = config('ai.pipeline.recovery_claim_hold_minutes', 10);

        if (! is_int($minutes) || $minutes <= 0) {
            throw new \UnexpectedValueException('ai.pipeline.recovery_claim_hold_minutes must be a positive integer.');
        }

        return $minutes;
    }

    private function featureEnabled(string $key): bool
    {
        $enabled = config($key);

        if (! is_bool($enabled)) {
            throw new \UnexpectedValueException("{$key} must be a boolean.");
        }

        return $enabled;
    }
}
