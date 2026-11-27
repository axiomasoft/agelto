<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Download\DownloadProgress;
use App\Dto\Procurement\Document\Download\DownloadResult;
use App\Dto\Procurement\Process\Inputs\DownloadInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Document\DownloadPolicy;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use Illuminate\Support\Facades\DB;

/** DB-only writer, invoked only after WorkFinalizer has fenced ownership and current input/head. */
final readonly class DownloadFinalizer implements StagePublicationRepository
{
    public function __construct(private DocumentVersionRepository $versions, private StorageCapacityRepository $capacity, private DownloadPolicy $policy, private DocumentWorkRepository $documentWorks) {}

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
        $work = $owner->workId === null || $owner->attemptId === null ? null : LedgerRow::one("SELECT id, intent_id FROM prc_process_work
            WHERE id=? AND document_id=? AND state='running' AND generation=? AND owner_token=?
              AND (intent_id=? OR (work_identity->'manual_retry'->>'intent_id' = intent_id::text AND work_identity->'input'->>'acquisition_intent_id' = ?))",
            [$owner->workId->value, $input->documentId, $owner->generation, $owner->ownerToken->value, $input->acquisitionIntentId->value, $input->acquisitionIntentId->value]);
        if ($work === null || ($activeIntentId = $work->nullableString('intent_id')) === null) {
            throw new \LogicException('Download artifact belongs to another execution owner.');
        }
        $revision = $this->versions->observeLocked($result->head, $result->artifact, $result->metadata);
        if (DB::update("UPDATE prc_operation_intents SET state='terminal',next_due_at=clock_timestamp()+(? * interval '1 second')
            WHERE id=? AND stage='download' AND subject_kind='prc_documents' AND subject_id=? AND state='active'
            AND expected_head_generation=? AND access_policy_hash=decode(?,'hex')", [$this->policy->revalidationSeconds,
            $activeIntentId, (string) $input->documentId, $input->expectedDocumentHeadGeneration, $input->accessPolicyHash->hex]) !== 1) {
            throw new \LogicException('Download acquisition intent fence failed.');
        }
        DB::update("UPDATE prc_documents SET next_revalidation_due_at=clock_timestamp()+(? * interval '1 second') WHERE id=?",
            [$this->policy->revalidationSeconds, $input->documentId]);

        $platform = LedgerRow::one('SELECT platform_id FROM prc_documents WHERE id=?', [$input->documentId])
            ?? throw new \LogicException('Missing document platform.');
        $this->documentWorks->prepareLocked($revision->id, $revision->head->generation, $platform->int('platform_id'));

        return [];
    }

    public function afterSuccessors(WorkInput $input, WorkId $workId, ProcessUuid $eventId, ProcessUuid $projectionId): void {}
}
