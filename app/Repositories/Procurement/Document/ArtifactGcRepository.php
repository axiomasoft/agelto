<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Storage\BlobDeletion;
use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Document\Storage\UnreferencedBlobCandidate;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Document\ArtifactDeletionState;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Deletion and cleanup lifecycle for unreferenced blobs and upload reservations. No object I/O here. */
final readonly class ArtifactGcRepository
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

    public function markDeletion(BlobReference $blob, int $graceSeconds): ?BlobDeletion
    {
        if ($graceSeconds < 900) {
            throw new \InvalidArgumentException('GC grace must cover the maximum download lease and retry horizon.');
        }

        return $this->transactions->run(function () use ($blob, $graceSeconds): ?BlobDeletion {
            $this->capacity->lockDomains([$blob->domainId]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($row === null || ArtifactRepository::reference($row) != $blob || $this->referenced($blob)) {
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
            if (ArtifactRepository::reference($row) != $blob || $row->int('deletion_generation') !== $deletion->generation
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
            blob: ArtifactRepository::reference($row),
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
}
