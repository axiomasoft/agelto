<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Download\DocumentReference;
use App\Dto\Procurement\Document\Download\DocumentReferenceId;
use App\Dto\Procurement\Document\Storage\ReferenceUpsertResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\SubjectKey;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\SubjectKind;
use App\Enums\Procurement\Process\Governance\IntentState;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sole acquisition reference writer. Runs after the declared identity/subject locks in fenced finalization. */
final readonly class DocumentCatalogRepository
{
    public function __construct(private WorkStoreRepository $works, private ProcurementStoreRepository $procurements) {}

    public function upsertReference(Procurement $procurement, DocumentReference $reference, ContentHash $accessPolicyHash): ReferenceUpsertResult
    {
        CallerTransaction::require('Document reference publication');
        if ($reference->procurement->platform->value !== $procurement->registry?->platform?->getAttribute('code')
            || $reference->procurement->type->value !== $procurement->source_identity_type
            || $reference->procurement->value->value !== $procurement->source_identity_value) {
            throw new \InvalidArgumentException('Document reference belongs to another procurement.');
        }
        $access = json_encode(['url' => $reference->url->reveal(), 'expires_at' => $reference->expiresAt?->format(DATE_ATOM)], JSON_THROW_ON_ERROR);
        // source_url remains NULL: the legacy downloader must never claim a Process reference.
        DB::insert('INSERT INTO prc_documents(uuid,procurement_id,platform_id,external_id,title,status,source_document_key,
            source_key_version,access_descriptor,access_schema_version,access_descriptor_version,reference_observed_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?::jsonb,1,1,clock_timestamp(),clock_timestamp(),clock_timestamp())
            ON CONFLICT(platform_id,source_document_key) WHERE source_document_key IS NOT NULL DO NOTHING',
            [(string) Str::uuid(), $procurement->id, $procurement->platform_id, $reference->externalId, $reference->title,
                DocumentStatus::Discovered->value, $reference->sourceKey->value, $reference->sourceKey->version, $access]);
        $row = LedgerRow::one('SELECT id,source_key_version,head_generation FROM prc_documents WHERE platform_id=? AND source_document_key=?',
            [$procurement->platform_id, $reference->sourceKey->value]) ?? throw new \LogicException('Missing document reference.');
        if ($row->int('source_key_version') !== $reference->sourceKey->version) {
            throw new \InvalidArgumentException('Document key codec version conflict.');
        }
        $id = $row->int('id');
        DB::update('UPDATE prc_documents SET title=?,access_descriptor_version=access_descriptor_version + CASE WHEN access_descriptor IS DISTINCT FROM ?::jsonb THEN 1 ELSE 0 END,
            access_descriptor=?::jsonb,reference_observed_at=clock_timestamp(),removed_at=NULL,updated_at=clock_timestamp() WHERE id=?', [$reference->title, $access, $access, $id]);
        DB::insert('INSERT INTO prc_procurement_documents(procurement_id,document_id,observed_at) VALUES (?,?,clock_timestamp())
            ON CONFLICT(procurement_id,document_id) DO UPDATE SET observed_at=clock_timestamp(),removed_at=NULL', [$procurement->id, $id]);
        $key = ContentHash::of('document-acquisition:'.$id.':1');
        $intent = ProcessUuid::derived('document-intent:'.$key->hex);
        $existing = LedgerRow::one('SELECT state FROM prc_operation_intents WHERE id=?', [$intent->value]);
        if ($existing === null) {
            DB::update('UPDATE prc_documents SET acquisition_cycle_seq=1 WHERE id=?', [$id]);
            $this->works->storeIntent($intent, $key, StageCode::Download, new SubjectKey(SubjectKind::Document, $id),
                ContentHash::of('document-reference:v1'), $accessPolicyHash, 1, $row->int('head_generation'), IntentState::Pending);
            DB::update("UPDATE prc_operation_intents SET pending_reason='handler_not_activated' WHERE id=?", [$intent->value]);
        }

        return new ReferenceUpsertResult(new DocumentReferenceId($id), new ProcessUuid($intent->value),
            $existing === null ? IntentState::Pending : IntentState::from($existing->string('state')));
    }

    /** @param list<int> $active */
    public function reconcile(int $procurementId, array $active): void
    {
        CallerTransaction::require('Document relationship reconciliation');
        $removed = DB::select('UPDATE prc_procurement_documents SET removed_at=clock_timestamp() WHERE procurement_id=? AND removed_at IS NULL
            AND document_id NOT IN (SELECT value::bigint FROM jsonb_array_elements_text(?::jsonb)) RETURNING document_id', [$procurementId, json_encode($active, JSON_THROW_ON_ERROR)]);
        foreach ($removed as $row) {
            if (! $row instanceof \stdClass || ! is_int($row->document_id)) {
                throw new \LogicException('Invalid document relationship identity.');
            }
            $this->procurements->storeEvent(procurementId: $procurementId,
                eventType: ProcurementEventType::DocumentRemoved, occurredAt: now(), entityRef: ['document_id' => $row->document_id]);
        }
        // Removing one procurement link cannot delete shared immutable bytes or cancel document work.
    }
}
