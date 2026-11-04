<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\AnalysisSubjects;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StatusRefreshInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;

/**
 * Набор subject locks для поддерживаемых stages. Без I/O: реальные subject rows
 * выводятся из immutable typed input, их heads
 * repository читает сам, а решение input invalidation принимается уже по locked heads.
 * Document и analysis stages требуют заранее загруженных ancestors.
 */
final readonly class SubjectLockResolver
{
    public function requiresAnalysisStorageLocks(StageCode $stage): bool
    {
        return in_array($stage, [StageCode::Screening, StageCode::Extraction, StageCode::Publication], true);
    }

    /** @return non-empty-list<SubjectKey> */
    public function subjects(WorkInput $input, ?int $documentId = null, ?AnalysisSubjects $analysis = null): array
    {
        if ($input instanceof ExtractionWindowInput || $input instanceof PublicationInput) {
            $analysis ??= throw new \InvalidArgumentException('Preloaded analysis ancestors required.');

            return [new SubjectKey(SubjectKind::Document, $analysis->documentId),
                new SubjectKey(SubjectKind::DocumentVersion, $analysis->versionId),
                new SubjectKey(SubjectKind::AnalysisRun, $analysis->runId),
                ...($analysis->windowId === null ? [] : [new SubjectKey(SubjectKind::AnalysisWindow, $analysis->windowId)])];
        }

        return match (true) {
            $input instanceof ListingInput => [new SubjectKey(SubjectKind::CrawlBlock, $input->crawlBlockId)],
            $input instanceof DetailInput => [new SubjectKey(SubjectKind::Procurement, $input->procurementId)],
            $input instanceof StatusRefreshInput => [new SubjectKey(SubjectKind::Procurement, $input->procurementId)],
            $input instanceof DownloadInput => [new SubjectKey(SubjectKind::Document, $input->documentId)],
            ($input instanceof PrepareInput || $input instanceof ScreeningInput) => [new SubjectKey(SubjectKind::Document, $documentId ?? throw new \InvalidArgumentException('Preloaded document ancestor required.')), new SubjectKey(SubjectKind::DocumentVersion, $input->documentVersionId)],
            default => throw new \InvalidArgumentException('Stage is not schema-supported by the current baseline: '.$input->stage()->value),
        };
    }

    /**
     * Текущий subject authority больше не соответствует immutable input work: работа подлежит
     * fenced supersede, а не исполнению. Listing — input_revision блока; detail/download —
     * expected head generation procurement/document. Status refresh сериализуется procurement
     * row; cycle invalidation определяется acquisition contract.
     *
     * @param  array<string, LockedSubject>  $locked  keyed by SubjectKey::key()
     */
    public function inputInvalidated(WorkInput $input, int $inputRevision, array $locked): bool
    {
        if ($input instanceof ExtractionWindowInput || $input instanceof PublicationInput) {
            $run = $locked[(new SubjectKey(SubjectKind::AnalysisRun, $input->analysisRunId))->key()]
                ?? throw new \LogicException('Analysis run must be locked.');
            $document = null;
            $version = null;
            foreach ($locked as $subject) {
                if ($subject->subject->kind === SubjectKind::Document) {
                    $document = $subject;
                } elseif ($subject->subject->kind === SubjectKind::DocumentVersion) {
                    $version = $subject;
                }
            }

            return $document === null || $version === null
                || $document->currentDocumentVersionId !== $version->subject->id
                || $document->headGeneration !== $run->expectedDocumentHeadGeneration
                || $version->analysisGeneration !== $run->expectedAnalysisGeneration
                || $version->currentAnalysisRunId != $input->analysisRunId
                || $run->recipeHash != $input->recipeHash
                || ($input instanceof PublicationInput && $input->resultGeneration !== $run->headGeneration);
        }
        if ($input instanceof PrepareInput || $input instanceof ScreeningInput) {
            $document = $version = null;
            foreach ($locked as $subject) {
                if ($subject->subject->kind === SubjectKind::Document) {
                    $document = $subject;
                } elseif ($subject->subject->kind === SubjectKind::DocumentVersion) {
                    $version = $subject;
                }
            }
            if ($document === null || $version === null) {
                throw new \LogicException('Prepare/screening requires both locked document ancestors.');
            }

            if ($input instanceof ScreeningInput) {
                // Screening's owner revision is the analysis generation, including a same-recipe replacement.
                return $document->currentDocumentVersionId !== $input->documentVersionId
                    || $version->analysisGeneration !== $inputRevision
                    || $document->headGeneration !== $version->expectedDocumentHeadGeneration
                    || $version->currentAnalysisRecipeHash != $input->screeningRecipeHash
                    || $version->currentAnalysisRepresentationHash != $input->representationHash;
            }

            return $document->headGeneration !== $inputRevision || $document->currentDocumentVersionId !== $input->documentVersionId;
        }
        $owner = $locked[$this->subjects($input)[0]->key()]
            ?? throw new \LogicException('Owner subject must be locked before invalidation check.');

        return match (true) {
            $input instanceof ListingInput => $owner->inputRevision !== $inputRevision,
            $input instanceof DetailInput => $owner->headGeneration !== $input->expectedHeadGeneration,
            $input instanceof DownloadInput => $owner->headGeneration !== $input->expectedDocumentHeadGeneration,
            default => false,
        };
    }
}
