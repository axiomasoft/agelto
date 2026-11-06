<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\BlobDeletion;
use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\UnreferencedBlobCandidate;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Document\VerifiedStoredArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\ArtifactDeletionState;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reference acquisition and deletion share the storage capacity authority. No object I/O here. */
final readonly class ArtifactRepository
{
    private const string UNREFERENCED_BLOB_PREDICATE = "NOT EXISTS (SELECT 1 FROM prc_storage_reservations s WHERE s.disk = b.disk AND s.object_key = b.object_key AND s.status = 'writing')
              AND NOT EXISTS (
                  SELECT 1 FROM prc_storage_reservations s
                  JOIN prc_process_work w ON w.id = s.work_id
                  WHERE s.disk = b.disk AND s.object_key = b.object_key
                    AND w.state NOT IN ('succeeded', 'failed_item', 'cancelled', 'superseded')
              )
              AND NOT EXISTS (
                  SELECT 1 FROM prc_storage_reservations s
                  JOIN prc_outbound_operations o ON o.id = s.operation_id
                  WHERE s.disk = b.disk AND s.object_key = b.object_key
                    AND o.state = 'open'
              )
              AND NOT EXISTS (SELECT 1 FROM prc_document_versions v WHERE v.blob_id = b.id)
              AND NOT EXISTS (SELECT 1 FROM prc_document_representations r WHERE r.blob_id = b.id)
              AND NOT EXISTS (SELECT 1 FROM prc_analysis_chunks c WHERE c.output_ref->>'disk' = b.disk AND c.output_ref->>'key' = b.object_key)";

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

    /** Stored imports have no upload reservation. Caller holds capacity before document/version locks. */
    public function adoptStoredLocked(VerifiedStoredArtifact $verified): BlobReference
    {
        CallerTransaction::require('Stored artifact adoption');
        $blob = $verified->blob;
        $existing = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex') FOR UPDATE", [$blob->domainId->value, $blob->hash->hex]);
        if ($existing !== null) {
            if (
                ($verified->catalogued && (
                    $existing->string('id') !== $blob->id->value
                    || $existing->string('object_key') !== $blob->objectKey
                ))
                || $existing->string('disk') !== $blob->disk
                || $existing->string('storage_domain_id') !== $blob->domainId->value
                || $existing->nullableString('deletion_marked_at') !== null
                || $existing->string('hash') !== $blob->hash->hex
                || $existing->int('size_bytes') !== $blob->sizeBytes
            ) {
                throw new ArtifactUnavailable('Stored object reference conflicts or is being deleted.');
            }

            return self::reference($existing);
        }
        if ($verified->catalogued) {
            throw new ArtifactUnavailable('Previously verified artifact was deleted; do not resurrect its reference.');
        }
        $this->capacity->accountStoredLocked($blob);
        DB::insert("INSERT INTO prc_document_blobs(id,storage_domain_id,content_hash,disk,object_key,size_bytes,verified_at) VALUES (?,?,decode(?,'hex'),?,?,?,clock_timestamp())", [$blob->id->value, $blob->domainId->value, $blob->hash->hex, $blob->disk, $blob->objectKey, $blob->sizeBytes]);

        return $blob;
    }

    public function storedReferenceAt(string $disk, string $objectKey): ?BlobReference
    {
        return self::storedReference(LedgerRow::one(
            "SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE disk=? AND object_key=?",
            [$disk, $objectKey],
        ));
    }

    public function storedReferenceByHash(ProcessUuid $domain, ContentHash $hash): ?BlobReference
    {
        return self::storedReference(LedgerRow::one(
            "SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE storage_domain_id=? AND content_hash=decode(?,'hex')",
            [$domain->value, $hash->hex],
        ));
    }

    /** Includes deletion-marked blobs: a catalogued key is never an unreferenced duplicate. */
    public function isCatalogued(string $disk, string $objectKey): bool
    {
        return LedgerRow::one('SELECT 1 FROM prc_document_blobs WHERE disk=? AND object_key=?', [$disk, $objectKey]) !== null;
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

    /** Durable repair ownership survives connection loss; an uncertain writer never loses its pin. */
    public function beginRepair(BlobReference $blob): bool
    {
        return $this->transactions->run(function () use ($blob): bool {
            $this->capacity->lockDomains([$blob->domainId]);
            $reservation = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$blob->id->value]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($row === null) {
                return false;
            }
            if (self::reference($row) != $blob || $row->nullableString('deletion_marked_at') !== null
                || $reservation === null || $reservation->string('status') !== 'transferred'
                || $reservation->string('domain_id') !== $blob->domainId->value
                || $reservation->string('disk') !== $blob->disk || $reservation->string('object_key') !== $blob->objectKey) {
                throw new ArtifactUnavailable('Canonical repair requires an unclaimed durable reservation.');
            }
            $domain = LedgerRow::one('SELECT * FROM prc_storage_domains WHERE id=?', [$blob->domainId->value]);
            $reserved = LedgerRow::one("SELECT COALESCE(SUM(byte_upper_bound),0)::bigint AS bytes FROM prc_storage_reservations
                WHERE domain_id=? AND status IN ('reserved','writing','staged','cleaning')", [$blob->domainId->value])?->int('bytes') ?? 0;
            if ($domain === null || $reservation->int('byte_upper_bound') > $domain->int('capacity_bytes') - $domain->int('accounted_durable_bytes') - $reserved) {
                throw new ArtifactUnavailable('Canonical repair storage capacity unavailable.');
            }
            DB::update("UPDATE prc_storage_reservations SET status='writing' WHERE id=?", [$blob->id->value]);

            return true;
        });
    }

    /** A confirmed completed write can release its extra charge even if read-back is unavailable. */
    public function finishRepair(BlobReference $blob): void
    {
        $this->transactions->run(function () use ($blob): void {
            $this->capacity->lockDomains([$blob->domainId]);
            $reservation = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$blob->id->value]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($reservation === null || $row === null || self::reference($row) != $blob || $row->nullableString('deletion_marked_at') !== null
                || $reservation->string('status') !== 'writing' || $reservation->string('domain_id') !== $blob->domainId->value
                || $reservation->string('disk') !== $blob->disk || $reservation->string('object_key') !== $blob->objectKey) {
                throw new ArtifactUnavailable('Canonical repair ownership changed.');
            }
            DB::update("UPDATE prc_storage_reservations SET status='transferred' WHERE id=?", [$blob->id->value]);
        });
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

    /** @return list<BlobReference> */
    public function repairCandidates(?ProcessUuid $afterId = null, int $limit = 25): array
    {
        $rows = LedgerRow::all(
            "SELECT b.*, encode(b.content_hash, 'hex') AS hash
             FROM prc_document_blobs b
             JOIN prc_storage_reservations s ON s.id = b.id
             WHERE s.status = 'writing' AND b.deletion_marked_at IS NULL
               AND (?::uuid IS NULL OR b.id>?::uuid)
             ORDER BY b.id LIMIT ?",
            [$afterId?->value, $afterId?->value, max(1, min(100, $limit))],
        );

        return array_map(self::reference(...), $rows);
    }

    /**
     * @return list<BlobReference>
     */
    public function unreferencedCandidates(int $graceSeconds = 900, int $limit = 25, ?string $afterVerifiedAt = null, ?ProcessUuid $afterId = null): array
    {
        return array_map(
            static fn (UnreferencedBlobCandidate $item): BlobReference => $item->blob,
            $this->unreferencedBatch($graceSeconds, $limit, $afterVerifiedAt, $afterId),
        );
    }

    /**
     * @return list<UnreferencedBlobCandidate>
     */
    public function unreferencedBatch(int $graceSeconds = 900, int $limit = 25, ?string $afterVerifiedAt = null, ?ProcessUuid $afterId = null): array
    {
        $cursorClause = $afterVerifiedAt !== null && $afterId !== null
            ? ' AND (b.verified_at, b.id) > (?::timestamptz, ?::uuid)'
            : '';
        $bindings = [max(900, $graceSeconds)];
        if ($cursorClause !== '') {
            $bindings[] = $afterVerifiedAt;
            $bindings[] = $afterId->value;
        }
        $bindings[] = max(1, min(100, $limit));

        $rows = LedgerRow::all("SELECT b.*, b.verified_at::text AS verified_at_cursor, encode(b.content_hash, 'hex') AS hash FROM prc_document_blobs b
            WHERE b.verified_at <= clock_timestamp() - (? * interval '1 second')
              AND ".self::UNREFERENCED_BLOB_PREDICATE
            .$cursorClause.' ORDER BY b.verified_at, b.id LIMIT ?', $bindings);

        return array_map(static fn (LedgerRow $row): UnreferencedBlobCandidate => new UnreferencedBlobCandidate(
            blob: self::reference($row),
            verifiedAt: $row->string('verified_at_cursor'),
        ), $rows);
    }

    private function checkpointed(LedgerRow $row): bool
    {
        $workId = $row->nullableString('work_id');

        return $workId !== null && $row->string('status') === 'staged'
            && DB::table('prc_process_work')->where('id', $workId)
                ->whereNotIn('state', ['succeeded', 'failed_item', 'cancelled', 'superseded'])
                ->where(static function (Builder $query): void {
                    $query->where('state', '!=', 'blocked')
                        ->orWhereNotIn('reason_code', ['document_quality', 'preparation_recipe_mismatch', 'policy_review']);
                })
                ->whereRaw("checkpoint->'stage_progress'->'payload'->'batches' @> jsonb_build_array(jsonb_build_object('artifact', jsonb_build_object('id', ?::text)))", [$row->string('id')])
                ->exists();
    }

    private function referenced(BlobReference $blob): bool
    {
        return LedgerRow::one(
            'SELECT 1 FROM (SELECT ?::uuid AS id, ?::text AS disk, ?::text AS object_key) b WHERE NOT ('.self::UNREFERENCED_BLOB_PREDICATE.')',
            [$blob->id->value, $blob->disk, $blob->objectKey],
        ) !== null;
    }

    private static function reference(LedgerRow $row): BlobReference
    {
        return new BlobReference(new ProcessUuid($row->string('id')), new ProcessUuid($row->string('storage_domain_id')),
            new ContentHash($row->string('hash')), $row->string('disk'), $row->string('object_key'), $row->int('size_bytes'));
    }

    private static function storedReference(?LedgerRow $row): ?BlobReference
    {
        if ($row === null) {
            return null;
        }
        if ($row->nullableString('deletion_marked_at') !== null) {
            throw new ArtifactUnavailable('Stored object metadata changed.');
        }

        return self::reference($row);
    }
}
