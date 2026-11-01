<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\BlobDeletion;
use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\ArtifactDeletionState;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Reference acquisition and deletion share the storage capacity authority. No object I/O here. */
final readonly class ArtifactRepository
{
    public function __construct(private StorageCapacityRepository $capacity, private ProcessTransaction $transactions) {}

    /** Caller holds all capacity domains BEFORE subjects/work; transfer and reference commit in its tx. */
    public function acquireLocked(VerifiedArtifact $artifact): BlobReference
    {
        CallerTransaction::require('Verified artifact acquisition');
        $request = $artifact->reservation;
        $reservation = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$request->id->value]) ?? throw new \LogicException('Missing upload reservation.');
        $this->capacity->assertIdentity($request, $reservation);
        if (! in_array($reservation->string('status'), ['staged', 'transferred'], true)) {
            throw new ArtifactUnavailable('Upload is being cleaned or is not verified.');
        }
        $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex') FOR UPDATE", [$request->domainId->value, $artifact->hash->hex]);
        if ($existing !== null) {
            if ($existing->nullableString('deletion_marked_at') !== null) {
                throw new ArtifactUnavailable('Blob deletion in progress; retry after verified cleanup.');
            }
            if ($existing->int('size_bytes') !== $artifact->sizeBytes) {
                throw new \LogicException('Content hash has inconsistent size.');
            }

            // Duplicate staged object remains charged until verified deletion of ITS unique key.
            return self::reference($existing);
        }
        if ($reservation->string('status') !== 'staged') {
            throw new ArtifactUnavailable('Previously transferred blob no longer exists.');
        }
        DB::insert("INSERT INTO prc_document_blobs(id,storage_domain_id,content_hash,disk,object_key,size_bytes,verified_at,deletion_generation)
            VALUES (?,?,decode(?,'hex'),?,?,?,clock_timestamp(),0)", [$request->id->value, $request->domainId->value,
            $artifact->hash->hex, $request->disk, $request->objectKey(), $artifact->sizeBytes]);
        DB::update('UPDATE prc_storage_domains SET accounted_durable_bytes=accounted_durable_bytes+? WHERE id=?', [$artifact->sizeBytes, $request->domainId->value]);
        DB::update("UPDATE prc_storage_reservations SET status='transferred' WHERE id=?", [$request->id->value]);

        return new BlobReference($request->id, $request->domainId, $artifact->hash, $request->disk, $request->objectKey(), $artifact->sizeBytes);
    }

    /** Full reference gate, called after the early capacity locks, before inserting any reference. */
    public function referenceLocked(BlobReference $blob): void
    {
        CallerTransaction::require('Blob reference gate');
        $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
        if ($row === null || $row->nullableString('deletion_marked_at') !== null || self::reference($row) != $blob) {
            throw new ArtifactUnavailable('Blob unavailable for a new reference.');
        }
    }

    public function markDeletion(BlobReference $blob, int $graceSeconds): ?BlobDeletion
    {
        if ($graceSeconds < 900) {
            throw new \InvalidArgumentException('GC grace must cover the maximum download lease and retry horizon.');
        }

        return $this->transactions->run(function () use ($blob, $graceSeconds): ?BlobDeletion {
            $this->capacity->lockDomains([$blob->domainId]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($row === null || self::reference($row) != $blob || $this->referenced($blob)) {
                return null;
            }
            if ($row->nullableString('deletion_marked_at') !== null) {
                return new BlobDeletion($blob, $row->int('deletion_generation'));
            }
            $changed = DB::update("UPDATE prc_document_blobs SET deletion_marked_at=clock_timestamp(),deletion_generation=deletion_generation+1
                WHERE id=? AND verified_at <= clock_timestamp() - (? * interval '1 second')", [$blob->id->value, $graceSeconds]);

            return $changed === 1 ? new BlobDeletion($blob, $row->int('deletion_generation') + 1) : null;
        });
    }

    public function deletionState(BlobDeletion $deletion): ArtifactDeletionState
    {
        return $this->transactions->run(function () use ($deletion): ArtifactDeletionState {
            $blob = $deletion->blob;
            $this->capacity->lockDomains([$blob->domainId]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($row === null) {
                return ArtifactDeletionState::Deleted;
            }
            if (self::reference($row) != $blob || $row->int('deletion_generation') !== $deletion->generation
                || $row->nullableString('deletion_marked_at') === null || $this->referenced($blob)) {
                return ArtifactDeletionState::Stale;
            }

            return ArtifactDeletionState::Ready;
        });
    }

    /** Called only after delete+absence verification outside the transaction. */
    public function finishDeletion(BlobDeletion $deletion): bool
    {
        return $this->transactions->run(function () use ($deletion): bool {
            $blob = $deletion->blob;
            $this->capacity->lockDomains([$blob->domainId]);
            $row = LedgerRow::one('SELECT * FROM prc_document_blobs WHERE id=? FOR UPDATE', [$blob->id->value]);
            if ($row === null) {
                return true;
            }
            if ($row->int('deletion_generation') !== $deletion->generation || $row->nullableString('deletion_marked_at') === null || $this->referenced($blob)) {
                return false;
            }
            DB::delete('DELETE FROM prc_document_blobs WHERE id=?', [$blob->id->value]);
            DB::update('UPDATE prc_storage_domains SET accounted_durable_bytes=accounted_durable_bytes-? WHERE id=?', [$blob->sizeBytes, $blob->domainId->value]);

            return true;
        });
    }

    public function beginCleanup(StorageReservationRequest $request): bool
    {
        return $this->transactions->run(function () use ($request): bool {
            $this->capacity->lockDomains([$request->domainId]);
            $row = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$request->id->value]) ?? throw new \LogicException('Missing reservation.');
            $this->capacity->assertIdentity($request, $row);
            if (! in_array($row->string('status'), ['reserved', 'staged', 'cleaning', 'cleaned'], true) || $this->checkpointed($row)) {
                return false;
            }
            if ($row->string('status') !== 'cleaned') {
                DB::update("UPDATE prc_storage_reservations SET status='cleaning' WHERE id=?", [$request->id->value]);
            }

            return true;
        });
    }

    public function finishCleanup(StorageReservationRequest $request): void
    {
        $this->transactions->run(function () use ($request): void {
            $this->capacity->lockDomains([$request->domainId]);
            $row = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$request->id->value]) ?? throw new \LogicException('Missing reservation.');
            $this->capacity->assertIdentity($request, $row);
            if (! in_array($row->string('status'), ['cleaning', 'cleaned'], true)) {
                throw new \LogicException('Cleanup ownership changed.');
            }
            DB::update("UPDATE prc_storage_reservations SET status='cleaned',cleanup_verified_at=COALESCE(cleanup_verified_at,clock_timestamp()) WHERE id=?", [$request->id->value]);
        });
    }

    private function checkpointed(LedgerRow $row): bool
    {
        $workId = $row->nullableString('work_id');

        return $workId !== null && $row->string('status') === 'staged'
            && DB::table('prc_process_work')->where('id', $workId)
                ->whereNotIn('state', ['succeeded', 'failed_item', 'cancelled', 'superseded'])
                ->whereRaw("checkpoint->'stage_progress'->'payload'->'batches' @> jsonb_build_array(jsonb_build_object('artifact', jsonb_build_object('id', ?::text)))", [$row->string('id')])
                ->exists();
    }

    private function referenced(BlobReference $blob): bool
    {
        return DB::table('prc_storage_reservations as r')
            ->join('prc_process_work as w', 'w.id', '=', 'r.work_id')
            ->where('r.disk', $blob->disk)->where('r.object_key', $blob->objectKey)
            ->whereNotIn('w.state', ['succeeded', 'failed_item', 'cancelled', 'superseded'])->exists()
            || DB::table('prc_storage_reservations as r')
                ->join('prc_outbound_operations as o', 'o.id', '=', 'r.operation_id')
                ->where('r.disk', $blob->disk)->where('r.object_key', $blob->objectKey)
                ->where('o.state', 'open')->exists()
            || DB::table('prc_document_versions')->where('blob_id', $blob->id->value)->exists()
            || DB::table('prc_document_representations')->where('blob_id', $blob->id->value)->exists();
    }

    private static function reference(LedgerRow $row): BlobReference
    {
        return new BlobReference(new ProcessUuid($row->string('id')), new ProcessUuid($row->string('storage_domain_id')),
            new ContentHash($row->string('hash')), $row->string('disk'), $row->string('object_key'), $row->int('size_bytes'));
    }
}
