<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\DownloadProgress;
use App\Dto\Procurement\Document\DownloadResult;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Document\DownloadPolicy;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use Illuminate\Support\Facades\DB;

/** DB-only writer, invoked only after WorkFinalizer has fenced ownership and current input/head. */
final readonly class DownloadFinalizer implements StagePublicationRepository
{
    public function __construct(private DocumentVersionRepository $versions, private StorageCapacityRepository $capacity, private DownloadPolicy $policy) {}

    public function publicationLocks(WorkInput $input, ValidatedStageResult $result): PublicationLocks
    {
        if (! $input instanceof DownloadInput || ! $result instanceof DownloadResult || $input->documentId !== $result->head->documentId) {
            throw new \InvalidArgumentException('Download result binding mismatch.');
        }

        return new DownloadCapacityLocks($this->capacity, [$result->artifact->reservation->domainId]);
    }

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        if (! $input instanceof DownloadInput || ! $progress instanceof DownloadProgress) {
            throw new \InvalidArgumentException('Download progress required.');
        }
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Fenced download publication');
        if (! $input instanceof DownloadInput || ! $result instanceof DownloadResult
            || $result->head->documentId !== $input->documentId || $result->head->generation !== $input->expectedDocumentHeadGeneration) {
            throw new \InvalidArgumentException('Download input/result mismatch.');
        }
        $owner = $result->artifact->reservation;
        if ($owner->workId === null || $owner->attemptId === null || LedgerRow::one("SELECT id FROM prc_process_work
            WHERE id=? AND intent_id=? AND document_id=? AND state='running' AND generation=? AND owner_token=?",
            [$owner->workId->value, $input->acquisitionIntentId->value, $input->documentId, $owner->generation, $owner->ownerToken->value]) === null) {
            throw new \LogicException('Download artifact belongs to another execution owner.');
        }
        $this->versions->observeLocked($result->head, $result->artifact, $result->metadata);
        if (DB::update("UPDATE prc_operation_intents SET state='terminal',next_due_at=clock_timestamp()+(? * interval '1 second')
            WHERE id=? AND stage='download' AND subject_kind='prc_documents' AND subject_id=? AND state='active'
            AND expected_head_generation=? AND access_policy_hash=decode(?,'hex')", [$this->policy->revalidationSeconds,
            $input->acquisitionIntentId->value, (string) $input->documentId, $input->expectedDocumentHeadGeneration, $input->accessPolicyHash->hex]) !== 1) {
            throw new \LogicException('Download acquisition intent fence failed.');
        }
        DB::update("UPDATE prc_documents SET next_revalidation_due_at=clock_timestamp()+(? * interval '1 second') WHERE id=?",
            [$this->policy->revalidationSeconds, $input->documentId]);

        // Prepare successor activation belongs to P3.3/P3.5, never invoke the legacy preparer here.
        return [];
    }

    public function afterSuccessors(WorkInput $input, WorkId $workId, ProcessUuid $eventId, ProcessUuid $projectionId): void {}
}
