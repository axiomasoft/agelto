<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Capacity tier precedes subjects/work. Slot release never releases storage reservations. */
final readonly class StorageCapacityRepository
{
    public function __construct(private ProcessTransaction $transactions) {}

    /** Explicit provisioning only, never boot-time schema/catalog mutation. */
    public function ensureDomain(ProcessUuid $id, string $key, int $capacity, int $highWatermark): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $key) !== 1 || $capacity < 1 || $highWatermark < 1 || $highWatermark > $capacity) {
            throw new \InvalidArgumentException('Invalid storage domain policy.');
        }
        DB::insert('INSERT INTO prc_storage_domains(id,domain_key,capacity_bytes,high_watermark_bytes,accounted_durable_bytes,policy_version)
            VALUES (?,?,?,?,0,1) ON CONFLICT (id) DO NOTHING', [$id->value, $key, $capacity, $highWatermark]);
        $row = LedgerRow::one('SELECT * FROM prc_storage_domains WHERE id=?', [$id->value]) ?? throw new \LogicException('Missing storage domain.');
        if ($row->string('domain_key') !== $key || $row->int('capacity_bytes') !== $capacity || $row->int('high_watermark_bytes') !== $highWatermark) {
            throw new \LogicException('Storage policy changes require a versioned capacity mutation.');
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
    public function reserveLocked(StorageReservationRequest $request): bool
    {
        CallerTransaction::require('Storage reservation');
        $existing = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=?', [$request->id->value]);
        if ($existing !== null) {
            $this->assertIdentity($request, $existing);

            return in_array($existing->string('status'), ['reserved', 'writing', 'staged'], true);
        }
        $domain = LedgerRow::one('SELECT * FROM prc_storage_domains WHERE id=?', [$request->domainId->value]) ?? throw new \LogicException('Missing storage domain.');
        $used = LedgerRow::one("SELECT COALESCE(SUM(byte_upper_bound),0)::bigint AS bytes FROM prc_storage_reservations WHERE domain_id=? AND status IN ('reserved','writing','staged','cleaning')", [$request->domainId->value])?->int('bytes') ?? 0;
        $available = $domain->int('capacity_bytes') - $domain->int('accounted_durable_bytes') - $used;
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

    /** Idempotent factual stop acknowledgement, called only after verified workspace deletion. */
    public function finishStoppedWrite(StorageReservationRequest $request): void
    {
        if ($request->phase !== StoragePhase::Prepare) {
            throw new \InvalidArgumentException('Prepare workspace required.');
        }
        $this->mutate($request, function (LedgerRow $row) use ($request): void {
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
