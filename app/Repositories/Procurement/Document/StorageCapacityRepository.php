<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Document\Storage\StorageCleanupCandidate;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Capacity tier precedes subjects/work. Slot release never releases storage reservations. */
final readonly class StorageCapacityRepository
{
    private const int IMPORT_LEASE_SECONDS = 120;

    private const string STORED_UPLOAD = "s.work_id IS NULL AND s.operation_id IS NULL AND s.phase = 'prepare'
        AND EXISTS (SELECT 1 FROM prc_storage_domains d WHERE d.id = s.domain_id AND d.domain_key = 'documents.import:' || s.disk)";

    public function __construct(private ProcessTransaction $transactions) {}

    /** Explicit provisioning only, never boot-time schema/catalog mutation. */
    public function ensureDomain(ProcessUuid $id, string $key, int $capacity, int $highWatermark): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $key) !== 1 || $capacity < 1 || $highWatermark < 1 || $highWatermark > $capacity) {
            throw new \InvalidArgumentException('Invalid storage domain policy.');
        }
        DB::insert('INSERT INTO prc_storage_domains(id,domain_key,capacity_bytes,high_watermark_bytes,accounted_durable_bytes,policy_version)
            VALUES (?,?,?,?,0,1) ON CONFLICT DO NOTHING', [$id->value, $key, $capacity, $highWatermark]);
        $row = LedgerRow::one('SELECT * FROM prc_storage_domains WHERE id=?', [$id->value]) ?? throw new \LogicException('Missing storage domain.');
        if ($row->string('domain_key') !== $key || $row->int('capacity_bytes') !== $capacity || $row->int('high_watermark_bytes') !== $highWatermark) {
            throw new \LogicException('Storage policy changes require a versioned capacity mutation.');
        }
    }

    /** Explicit stored-import provisioning, independent of the reservation-backed upload lifecycle. */
    public function provisionImportDomain(string $disk): ProcessUuid
    {
        $key = 'documents.import:'.$disk;
        $domain = ProcessUuid::derived($key);
        // Dotted storage keys are literal array keys.
        $policies = config('documents.storage');
        $limits = is_array($policies) ? ($policies['documents.originals'] ?? null) : null;
        if (! is_array($limits) || ! is_int($limits['capacity_bytes'] ?? null) || ! is_int($limits['high_watermark_bytes'] ?? null)) {
            throw new \LogicException('Missing documents storage policy.');
        }
        $this->ensureDomain($domain, $key, $limits['capacity_bytes'], $limits['high_watermark_bytes']);

        return $domain;
    }

    public function reserveStoredUpload(string $disk, int $sizeBytes): StorageReservationRequest
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Stored upload reservation requires its own transaction.');
        }
        if ($sizeBytes < 0 || $sizeBytes > config()->integer('documents.download.max_bytes')) {
            throw new ArtifactUnavailable('Stored object exceeds the document byte ceiling.');
        }
        $request = new StorageReservationRequest(
            id: new ProcessUuid((string) Str::uuid()),
            domainId: $this->provisionImportDomain($disk),
            ownerToken: new ProcessUuid((string) Str::uuid()),
            generation: 1,
            byteUpperBound: max(1, $sizeBytes),
            phase: StoragePhase::Prepare,
            disk: $disk,
        );
        $this->transactions->run(function () use ($request): void {
            $this->lockDomains([$request->domainId]);
            if (! $this->reserveLocked(request: $request, useHighWatermark: true)) {
                throw new ArtifactUnavailable('Stored object capacity unavailable.');
            }
        });

        return $request;
    }

    public function assertStoredUploadLease(StorageReservationRequest $request): void
    {
        if ($this->storedUploadLeaseExpired(request: $request, graceSeconds: 0) !== false) {
            throw new ArtifactUnavailable('Stored upload requires a live writer lease.');
        }
    }

    public function storedUploadLeaseExpired(StorageReservationRequest $request, int $graceSeconds): ?bool
    {
        $predicate = self::STORED_UPLOAD;

        return LedgerRow::one(
            sql: "SELECT (s.status NOT IN ('reserved', 'writing', 'staged') OR clock_timestamp() >= s.created_at + make_interval(secs => ?)) AS expired
             FROM prc_storage_reservations s WHERE s.id = ? AND s.owner_token = ? AND s.generation = ? AND {$predicate}",
            bindings: [self::IMPORT_LEASE_SECONDS + $graceSeconds, $request->id->value, $request->ownerToken->value, $request->generation],
        )?->bool('expired');
    }

    /** Caller holds the blob's capacity domain; accounting and import publication commit together. */
    public function accountStoredLocked(BlobReference $blob): void
    {
        CallerTransaction::require('Stored artifact accounting');
        $changed = DB::update("UPDATE prc_storage_domains SET accounted_durable_bytes=accounted_durable_bytes+? WHERE id=? AND accounted_durable_bytes+?+
            (SELECT coalesce(sum(byte_upper_bound),0) FROM prc_storage_reservations WHERE domain_id=? AND status IN ('reserved','writing','staged','cleaning')) <= high_watermark_bytes", [$blob->sizeBytes, $blob->domainId->value, $blob->sizeBytes, $blob->domainId->value]);
        if ($changed !== 1) {
            throw new ArtifactUnavailable('Stored object capacity unavailable.');
        }
    }

    /** @param list<ProcessUuid> $domains */
    public function lockDomains(array $domains): void
    {
        CallerTransaction::require('Storage capacity locks');
        $ids = array_values(array_unique(array_map(fn (ProcessUuid $id): string => $id->value, $domains)));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            if (LedgerRow::one('SELECT id FROM prc_storage_domains WHERE id=? FOR UPDATE', [$id]) === null) {
                throw new \LogicException('Unprovisioned storage domain.');
            }
        }
    }

    /** Caller holds this domain in the complete early capacity tier. False means storage_capacity. */
    public function reserveLocked(StorageReservationRequest $request, bool $useHighWatermark = false): bool
    {
        CallerTransaction::require('Storage reservation');
        $existing = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=?', [$request->id->value]);
        if ($existing !== null) {
            $this->assertIdentity($request, $existing);

            return in_array($existing->string('status'), ['reserved', 'writing', 'staged'], true);
        }
        $domain = LedgerRow::one('SELECT * FROM prc_storage_domains WHERE id=?', [$request->domainId->value]) ?? throw new \LogicException('Missing storage domain.');
        $used = LedgerRow::one("SELECT COALESCE(SUM(byte_upper_bound),0)::bigint AS bytes FROM prc_storage_reservations WHERE domain_id=? AND status IN ('reserved','writing','staged','cleaning')", [$request->domainId->value])?->int('bytes') ?? 0;
        $limit = $useHighWatermark ? $domain->int('high_watermark_bytes') : $domain->int('capacity_bytes');
        $available = $limit - $domain->int('accounted_durable_bytes') - $used;
        if ($request->byteUpperBound > $available) {
            return false;
        }
        DB::insert("INSERT INTO prc_storage_reservations(id,domain_id,work_id,attempt_id,operation_id,upload_intent_id,owner_token,generation,byte_upper_bound,phase,status,disk,object_key,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,'reserved',?,?,clock_timestamp())", [$request->id->value, $request->domainId->value,
            $request->workId?->value, $request->attemptId?->value, $request->operationId?->value, $request->id->value,
            $request->ownerToken->value, $request->generation, $request->byteUpperBound, $request->phase->value, $request->disk, $request->objectKey()]);

        return true;
    }

    /** Single writer claim. A crash in writing retains the full charge until verified stop/cleanup. */
    public function beginWrite(StorageReservationRequest $request): void
    {
        $this->mutate($request, function (LedgerRow $row) use ($request): void {
            if ($row->string('status') !== 'reserved') {
                throw new \LogicException('Upload already claimed; verify or reconcile instead of overwriting.');
            }
            DB::update("UPDATE prc_storage_reservations SET status='writing' WHERE id=?", [$request->id->value]);
        });
    }

    public function finishWrite(StorageReservationRequest $request): void
    {
        $this->mutate($request, function (LedgerRow $row) use ($request): void {
            if ($row->string('status') !== 'writing') {
                throw new \LogicException('Upload writer ownership changed.');
            }
            DB::update("UPDATE prc_storage_reservations SET status='staged' WHERE id=?", [$request->id->value]);
        });
    }

    /** Idempotent writer stop acknowledgement. The reservation stays charged until verified cleanup. */
    public function finishStoppedWrite(StorageReservationRequest $request): void
    {
        $this->mutate($request, function (LedgerRow $row) use ($request): void {
            if (LedgerRow::one('SELECT 1 AS canonical FROM prc_document_blobs WHERE id=? OR (disk=? AND object_key=?)', [$request->id->value, $request->disk, $request->objectKey()]) !== null) {
                throw new \LogicException('Canonical blob reservation cannot be stopped as staging.');
            }
            if ($row->string('status') === 'writing') {
                DB::update("UPDATE prc_storage_reservations SET status='staged' WHERE id=?", [$request->id->value]);
            } elseif (! in_array($row->string('status'), ['staged', 'cleaning', 'cleaned'], true)) {
                throw new \LogicException('Workspace stop acknowledgement has no writer.');
            }
        });
    }

    public function assertStaged(StorageReservationRequest $request): void
    {
        $row = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=?', [$request->id->value]) ?? throw new \LogicException('Missing reservation.');
        $this->assertIdentity($request, $row);
        if ($row->string('status') !== 'staged') {
            throw new \LogicException('Object is not a stopped, staged upload.');
        }
    }

    public function assertIdentity(StorageReservationRequest $request, LedgerRow $row): void
    {
        if ($row->string('domain_id') !== $request->domainId->value || $row->string('owner_token') !== $request->ownerToken->value
            || $row->int('generation') !== $request->generation || $row->int('byte_upper_bound') !== $request->byteUpperBound
            || $row->string('phase') !== $request->phase->value || $row->string('disk') !== $request->disk
            || $row->string('object_key') !== $request->objectKey() || $row->string('upload_intent_id') !== $request->id->value
            || $row->nullableString('work_id') !== $request->workId?->value || $row->nullableString('attempt_id') !== $request->attemptId?->value
            || $row->nullableString('operation_id') !== $request->operationId?->value) {
            throw new \LogicException('Storage reservation identity changed.');
        }
    }

    /** @return list<StorageCleanupCandidate> */
    public function cleanupCandidates(?ProcessUuid $afterId = null, int $limit = 50): array
    {
        $storedUpload = self::STORED_UPLOAD;
        $rows = LedgerRow::all(
            "SELECT s.* FROM prc_storage_reservations s
             LEFT JOIN prc_process_work w ON w.id = s.work_id
             LEFT JOIN prc_outbound_operations o ON o.id = s.operation_id
             WHERE s.status IN ('reserved','writing','staged','cleaning')
               AND NOT EXISTS (SELECT 1 FROM prc_document_blobs b WHERE b.disk=s.disk AND b.object_key=s.object_key)
               AND (
                   (s.work_id IS NOT NULL AND (w.state<>'running' OR w.generation<>s.generation OR w.owner_token IS DISTINCT FROM s.owner_token))
                   OR (s.work_id IS NULL AND s.operation_id IS NOT NULL AND o.state<>'open')
                   OR (({$storedUpload}) AND clock_timestamp() >= s.created_at + make_interval(secs => ?))
               )
               AND (s.operation_id IS NULL OR o.state<>'open')
               AND (?::uuid IS NULL OR s.id>?::uuid)
             ORDER BY s.id LIMIT ?",
            [self::IMPORT_LEASE_SECONDS, $afterId?->value, $afterId?->value, max(1, min(100, $limit))],
        );

        return array_map(static fn (LedgerRow $row): StorageCleanupCandidate => new StorageCleanupCandidate(
            reservation: new StorageReservationRequest(
                id: new ProcessUuid($row->string('id')),
                domainId: new ProcessUuid($row->string('domain_id')),
                ownerToken: new ProcessUuid($row->string('owner_token')),
                generation: $row->int('generation'),
                byteUpperBound: $row->int('byte_upper_bound'),
                phase: StoragePhase::from($row->string('phase')),
                disk: $row->string('disk'),
                workId: self::uuid($row->nullableString('work_id')),
                attemptId: self::uuid($row->nullableString('attempt_id')),
                operationId: self::uuid($row->nullableString('operation_id')),
            ),
            writing: $row->string('status') === 'writing',
        ), $rows);
    }

    private static function uuid(?string $value): ?ProcessUuid
    {
        return $value === null ? null : new ProcessUuid($value);
    }

    /** @param \Closure(LedgerRow): void $write */
    private function mutate(StorageReservationRequest $request, \Closure $write): void
    {
        $this->transactions->run(function () use ($request, $write): void {
            $this->lockDomains([$request->domainId]);
            $row = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$request->id->value]) ?? throw new \LogicException('Missing reservation.');
            $this->assertIdentity($request, $row);
            $write($row);
        });
    }
}
