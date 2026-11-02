<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\DocumentRange;
use App\Dto\Procurement\Document\PreparedDocument;
use App\Dto\Procurement\Document\PrepareOcrCheckpoint;
use App\Dto\Procurement\Document\RepresentationCoverage;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Document\CoverageUnit;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use Illuminate\Support\Facades\DB;

final readonly class PrepareFinalizer implements StagePublicationRepository
{
    public function __construct(private DocumentRepresentationRepository $representations, private StorageCapacityRepository $capacity, private PreparePolicy $policy) {}

    public function publicationLocks(WorkInput $input, ValidatedStageResult $result): PublicationLocks
    {
        if (! $result instanceof PreparedDocument) {
            throw new \InvalidArgumentException('Prepared result required.');
        }

        return new DownloadCapacityLocks($this->capacity, [$result->text->reservation->domainId, ...array_map(fn ($b): ProcessUuid => $b->artifact->reservation->domainId, $result->manifest->batches)]);
    }

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        if (! $input instanceof PrepareInput || ! $progress instanceof PrepareOcrCheckpoint || ($progress->recipeHash !== null && $input->preparationRecipeHash != $progress->recipeHash)) {
            throw new \InvalidArgumentException('Preparation checkpoint binding mismatch.');
        }
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Fenced preparation publication');
        if (! $input instanceof PrepareInput || ! $result instanceof PreparedDocument || $input->documentVersionId !== $result->head->versionId
            || $input->preparationRecipeHash != $result->manifest->recipeHash || ! $result->manifest->coverage()->complete()) {
            throw new \InvalidArgumentException('Incomplete or unbound preparation.');
        }
        $source = LedgerRow::one("SELECT encode(content_hash,'hex') AS content_hash FROM prc_document_versions WHERE id=? AND document_id=?", [$input->documentVersionId, $result->head->documentId]);
        if ($source === null || $source->string('content_hash') !== $result->manifest->sourceHash?->hex || $input->preparationRecipeHash != $this->policy->hash) {
            throw new \InvalidArgumentException('Preparation result source/recipe mismatch.');
        }
        $owner = $result->text->reservation;
        if ($owner->workId === null || LedgerRow::one("SELECT id FROM prc_process_work WHERE id=? AND document_version_id=? AND generation=? AND owner_token=? AND state='running'",
            [$owner->workId->value, $input->documentVersionId, $owner->generation, $owner->ownerToken->value]) === null) {
            throw new \LogicException('Preparation artifact owner mismatch.');
        }
        foreach ($result->manifest->batches as $batch) {
            if ($batch->artifact->reservation->workId != $owner->workId) {
                throw new \LogicException('Batch belongs to another work.');
            }
            $start = $batch->pages[0]->page - 1;
            $end = $start + count($batch->pages);
            $missing = [];
            if ($start > 0) {
                $missing[] = new DocumentRange(0, $start);
            }
            if ($end < $result->manifest->originalPages) {
                $missing[] = new DocumentRange($end, $result->manifest->originalPages);
            }
            $this->representations->storeLocked($result->head, $batch->artifact, $input->preparationRecipeHash, RepresentationKind::Text,
                'pages:'.$start.':'.$end, $this->policy->toolsVersion, 'text/plain', new RepresentationCoverage(CoverageUnit::Page, $result->manifest->originalPages, [new DocumentRange($start, $end)], $missing));
        }
        $this->representations->storeLocked($result->head, $result->text, $input->preparationRecipeHash, RepresentationKind::Text,
            'complete', $this->policy->toolsVersion, 'text/plain', new RepresentationCoverage(CoverageUnit::Page,
                $result->manifest->originalPages, $result->manifest->coverage()->covered, [], $result->manifest->pages(),
                $result->manifest->qualityLabel($this->policy->qualityThreshold)));

        $key = ContentHash::of('screening-pending:'.$input->documentVersionId.':'.$input->preparationRecipeHash->hex.':'.$result->text->hash->hex);
        $id = ListingStoreRepository::id('screening-intent:'.$key->hex);
        if (LedgerRow::one('SELECT id FROM prc_operation_intents WHERE id=?', [$id->value]) === null) {
            app(WorkStoreRepository::class)->storeIntent($id, $key,
                StageCode::Screening,
                new SubjectKey(SubjectKind::DocumentVersion, $input->documentVersionId),
                $input->preparationRecipeHash, $result->text->hash, 1, $result->head->generation,
                IntentState::Pending);
            DB::update("UPDATE prc_operation_intents SET pending_reason='handler_not_activated' WHERE id=?", [$id->value]);
        }

        DB::update("UPDATE prc_documents SET status='prepared' WHERE id=?", [$result->head->documentId]);

        return []; // P3.13 supplies the real recipe, paid ledger and handler activation.
    }

    public function afterSuccessors(WorkInput $input, WorkId $workId, ProcessUuid $eventId, ProcessUuid $projectionId): void {}
}
