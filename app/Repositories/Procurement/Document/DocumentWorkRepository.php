<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\PrepareInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\RunKind;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\RunStoreRepository;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Independent document producers. They create ready work, never admission or queue messages. */
final readonly class DocumentWorkRepository
{
    public function __construct(private WorkStoreRepository $works, private RunStoreRepository $runs,
        private TransitionRepository $transitions, private ProcessTransaction $transactions, private PreparePolicy $prepare) {}

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
            $head = LedgerRow::one('SELECT current_version_id,head_generation,platform_id FROM prc_documents WHERE id=? FOR UPDATE', [$documentId]);
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
        $id = new WorkId(ListingStoreRepository::id('document-work:'.$key));
        $run = ListingStoreRepository::id('document-run:'.$key);
        $this->runs->storeRun($run, $manual ? RunKind::Manual : RunKind::Profile, 'document:'.$key, null,
            new VersionedPayload('{"pipeline":"documents","version":1}', 1), $requestedBy,
            ListingStoreRepository::id('document-run-event:'.$key), ListingStoreRepository::id('document-run-project:'.$key));
        $this->works->storeWork($id, $input, $generation, $intent, $cohort, $platformId, $run, 100,
            ListingStoreRepository::id('document-event:'.$key), ListingStoreRepository::id('document-project:'.$key));
        $this->works->linkRun($run, $id, RunWorkRelation::Created, AccountingRole::Required);
        DB::update("UPDATE prc_process_runs SET discovery_outcome='complete',discovery_closed_at=clock_timestamp(),transition_seq=2 WHERE id=?", [$run->value]);
        $this->transitions->recordRun(ListingStoreRepository::id('document-close:'.$key), ListingStoreRepository::id('document-close-project:'.$key),
            $run, 2, 'collecting', 'complete', null, ActorKind::System);

        return true;
    }
}
