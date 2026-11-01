<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Allocates durable due cycles only. Independent feeder activation belongs to P3.5. */
final readonly class DocumentRevalidator
{
    public function __construct(private ProcessTransaction $transactions, private WorkStoreRepository $works) {}

    public function allocate(int $documentId): ?ProcessUuid
    {
        if ($documentId < 1) {
            throw new \InvalidArgumentException('Document identity required.');
        }
        $intent = new ProcessUuid((string) Str::uuid());

        return $this->transactions->run(function () use ($documentId, $intent): ?ProcessUuid {
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
            $this->works->storeIntent($intent, ContentHash::of('document-acquisition:'.$documentId.':'.$cycle), StageCode::Download,
                new SubjectKey(SubjectKind::Document, $documentId), new ContentHash($prior->string('semantic')),
                new ContentHash($prior->string('access')), $cycle, $head->int('head_generation'), IntentState::Pending);
            DB::update("UPDATE prc_operation_intents SET pending_reason='handler_not_activated' WHERE id=?", [$intent->value]);
            DB::update('UPDATE prc_documents SET acquisition_cycle_seq=?,next_revalidation_due_at=NULL WHERE id=?', [$cycle, $documentId]);

            return $intent;
        });
    }
}
