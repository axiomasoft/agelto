<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Execution\WorkIdentity;
use App\Dto\Procurement\Process\Governance\TransitionData;
use App\Dto\Procurement\Process\Inputs\DownloadInput;
use App\Dto\Procurement\Process\Inputs\PrepareInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\SubjectKey;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\RunKind;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\SubjectKind;
use App\Enums\Procurement\Process\Governance\ActorKind;
use App\Enums\Procurement\Process\Governance\IntentState;
use App\Repositories\Procurement\Process\Projection\RunStoreRepository;
use App\Repositories\Procurement\Process\Projection\TransitionRepository;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Independent document producers. They create ready work, never admission or queue messages. */
final readonly class DocumentWorkRepository
{
    public function __construct(private WorkStoreRepository $works, private RunStoreRepository $runs,
        private TransitionRepository $transitions, private ProcessTransaction $transactions, private PreparePolicy $prepare) {}

    /** @return list<int> */
    public function dueRevalidationDocumentIds(): array
    {
        return array_map(static fn (LedgerRow $row): int => $row->int('id'), LedgerRow::all(
            "SELECT id FROM prc_documents WHERE source_document_key IS NOT NULL AND removed_at IS NULL
            AND current_version_id IS NOT NULL AND next_revalidation_due_at<=clock_timestamp()
            AND NOT EXISTS (SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_documents'
                AND i.subject_id=prc_documents.id::text AND i.stage='download' AND i.state IN ('active','pending'))
            ORDER BY next_revalidation_due_at,id LIMIT 50",
        ));
    }

    /** @return list<int> */
    public function pendingDownloadDocumentIds(): array
    {
        return array_map(static fn (LedgerRow $row): int => $row->int('id'), LedgerRow::all(
            "SELECT d.id FROM prc_documents d WHERE d.removed_at IS NULL AND EXISTS
            (SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_documents' AND i.subject_id=d.id::text
            AND i.stage='download' AND i.state='pending') AND NOT EXISTS
            (SELECT 1 FROM prc_operation_intents i WHERE i.subject_kind='prc_documents' AND i.subject_id=d.id::text
            AND i.stage='download' AND i.state='active') ORDER BY d.id LIMIT 50",
        ));
    }

    /**
     * Existing blocked/failed prepare work is not recreated by polling.
     *
     * @return list<int>
     */
    public function missingPreparationDocumentIds(): array
    {
        return array_map(static fn (LedgerRow $row): int => $row->int('id'), LedgerRow::all(
            "SELECT d.id FROM prc_documents d WHERE d.removed_at IS NULL AND d.current_version_id IS NOT NULL AND NOT EXISTS
            (SELECT 1 FROM prc_process_work w WHERE w.stage='prepare' AND w.document_version_id=d.current_version_id
            AND w.input_payload->>'preparation_recipe_hash'=?) ORDER BY d.id LIMIT 50",
            [$this->prepare->hash->hex],
        ));
    }

    /** Revalidate due/head/policy under the document lock in the caller's transaction. */
    public function allocateRevalidationLocked(int $documentId, ProcessUuid $intentId): ?ProcessUuid
    {
        CallerTransaction::require('Document revalidation allocation');
        $head = LedgerRow::one('SELECT head_generation,acquisition_cycle_seq FROM prc_documents WHERE id=?
                AND source_document_key IS NOT NULL AND removed_at IS NULL AND current_version_id IS NOT NULL
                AND next_revalidation_due_at<=clock_timestamp() FOR UPDATE', [$documentId]);
        if ($head === null || LedgerRow::one("SELECT id FROM prc_operation_intents WHERE stage='download'
                AND subject_kind='prc_documents' AND subject_id=? AND state IN ('active','pending')", [(string) $documentId]) !== null) {
            return null;
        }
        $prior = LedgerRow::one("SELECT encode(semantic_policy_hash,'hex') AS semantic,encode(access_policy_hash,'hex') AS access
                FROM prc_operation_intents WHERE stage='download' AND subject_kind='prc_documents' AND subject_id=?
                ORDER BY cycle_seq DESC LIMIT 1", [(string) $documentId]) ?? throw new \LogicException('Missing initial acquisition policy.');
        $cycle = $head->int('acquisition_cycle_seq') + 1;
        $this->works->storeIntent(
            id: $intentId,
            intentKey: ContentHash::of('document-acquisition:'.$documentId.':'.$cycle),
            stage: StageCode::Download,
            subject: new SubjectKey(SubjectKind::Document, $documentId),
            semanticPolicyHash: new ContentHash($prior->string('semantic')),
            accessPolicyHash: new ContentHash($prior->string('access')),
            cycleSeq: $cycle,
            expectedHeadGeneration: $head->int('head_generation'),
            state: IntentState::Pending,
        );
        DB::update("UPDATE prc_operation_intents SET pending_reason='handler_not_activated' WHERE id=?", [$intentId->value]);
        DB::update('UPDATE prc_documents SET acquisition_cycle_seq=?,next_revalidation_due_at=NULL WHERE id=?', [$cycle, $documentId]);

        return $intentId;
    }

    public function activate(int $documentId): int
    {
        return $this->transactions->run(function () use ($documentId): int {
            $document = LedgerRow::one('SELECT id,head_generation,platform_id FROM prc_documents WHERE id=? AND removed_at IS NULL FOR UPDATE', [$documentId]);
            if ($document === null || LedgerRow::one("SELECT id FROM prc_operation_intents WHERE subject_kind='prc_documents'
                AND subject_id=? AND stage='download' AND state='active'", [(string) $documentId]) !== null) {
                return 0;
            }
            $intent = LedgerRow::one("SELECT id,encode(access_policy_hash,'hex') AS access FROM prc_operation_intents
                WHERE subject_kind='prc_documents' AND subject_id=? AND stage='download' AND state='pending'
                ORDER BY cycle_seq,id LIMIT 1 FOR UPDATE", [(string) $documentId]);
            if ($intent === null) {
                return 0;
            }
            $id = new ProcessUuid($intent->string('id'));
            DB::update("UPDATE prc_operation_intents SET state='active',pending_reason=NULL,expected_head_generation=? WHERE id=?",
                [$document->int('head_generation'), $id->value]);
            $this->createLocked(new DownloadInput($documentId, $id, $document->int('head_generation'), new ContentHash($intent->string('access'))),
                $document->int('head_generation'), $document->int('platform_id'), $id);

            return 1;
        });
    }

    public function prepare(int $documentId, ?int $requestedBy = null, bool $manual = false): int
    {
        return $this->transactions->run(function () use ($documentId, $requestedBy, $manual): int {
            $head = LedgerRow::one('SELECT current_version_id,head_generation,platform_id FROM prc_documents WHERE id=? AND removed_at IS NULL FOR UPDATE', [$documentId]);
            if ($head === null || $head->nullableInt('current_version_id') === null) {
                return 0;
            }

            return $this->createLocked(new PrepareInput($head->int('current_version_id'), $this->prepare->hash), $head->int('head_generation'), $head->int('platform_id'), null, $requestedBy, $manual) ? 1 : 0;
        });
    }

    /** Called by the fenced download finalizer, atomically with immutable revision publication. */
    public function prepareLocked(int $versionId, int $generation, int $platformId): bool
    {
        CallerTransaction::require('Prepare production');

        return $this->createLocked(new PrepareInput($versionId, $this->prepare->hash), $generation, $platformId, null);
    }

    private function createLocked(WorkInput $input, int $generation, int $platformId, ?ProcessUuid $intent, ?int $requestedBy = null, bool $manual = false): bool
    {
        $document = $input instanceof DownloadInput
            ? LedgerRow::one('SELECT procurement_id FROM prc_documents WHERE id=?', [$input->documentId])
            : LedgerRow::one('SELECT d.procurement_id FROM prc_documents d JOIN prc_document_versions v ON v.document_id=d.id WHERE v.id=?',
                [$input instanceof PrepareInput ? $input->documentVersionId : throw new \LogicException('Unsupported producer input.')]);
        $cohort = $document?->nullableInt('procurement_id') === null ? 'manual' : 'platform:'.$platformId;
        $key = (new WorkIdentity($input))->key()->hex;
        if (LedgerRow::one("SELECT id FROM prc_process_work WHERE work_key=decode(?,'hex')", [$key]) !== null) {
            return false;
        }
        $id = new WorkId(ProcessUuid::derived('document-work:'.$key));
        $run = ProcessUuid::derived('document-run:'.$key);
        $this->runs->storeRun($run, $manual ? RunKind::Manual : RunKind::Profile, 'document:'.$key, null,
            new VersionedPayload('{"pipeline":"documents","version":1}', 1), $requestedBy,
            ProcessUuid::derived('document-run-event:'.$key), ProcessUuid::derived('document-run-project:'.$key));
        $this->works->storeWork(new StoreWorkData(
            id: $id,
            input: $input,
            inputRevision: $generation,
            intentId: $intent,
            cohortKey: $cohort,
            platformId: $platformId,
            originRunId: $run,
            priority: 100,
            eventId: ProcessUuid::derived('document-event:'.$key),
            projectionMessageId: ProcessUuid::derived('document-project:'.$key),
        ));
        $this->works->linkRun($run, $id, RunWorkRelation::Created, AccountingRole::Required);
        DB::update("UPDATE prc_process_runs SET discovery_outcome='complete',discovery_closed_at=clock_timestamp(),transition_seq=2 WHERE id=?", [$run->value]);
        $this->transitions->recordRun(new TransitionData(
            eventId: ProcessUuid::derived('document-close:'.$key),
            projectionMessageId: ProcessUuid::derived('document-close-project:'.$key),
            entityId: $run,
            entitySeq: 2,
            previousState: 'collecting',
            newState: 'complete',
            reasonCode: null,
            actor: ActorKind::System,
        ));

        return true;
    }
}
