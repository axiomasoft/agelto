<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Acquisition\Control\LockedProcurement;
use App\Dto\Procurement\Acquisition\Control\ProcurementIdentity;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\ProcurementIngestProcessor;
use App\Support\Database\PostgresAdvisoryLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** DB-only publication. Process callers acquire these locks BEFORE their work lock. */
final readonly class SnapshotFinalizer
{
    public function __construct(private IdentityResolver $identities, private PostgresAdvisoryLock $locks,
        private ProcurementReadRepository $read, private ProcurementIngestProcessor $ingest,
        private ExtensionWriter $extensions) {}

    /** Legacy entrypoint until producer cutover; HTTP has already completed. */
    public function ingest(ProcurementSnapshotData $data, ?ProcessUuid $processRunId = null, ?Carbon $observedAt = null): Procurement
    {
        $identity = $this->identities->resolve($data->registryId, $data->identity);
        $this->extensions->validate($data->identity->platform, $data->extension);

        return DB::transaction(fn (): Procurement => $this->persistLocked($data, $this->lockIdentities([$identity])[0], $processRunId, $observedAt));
    }

    /**
     * Identity advisory keys first, existing subjects in primary-key order second.
     *
     * @param  list<ProcurementIdentity>  $identities
     * @return list<LockedProcurement>
     */
    public function lockIdentities(array $identities): array
    {
        CallerTransaction::require('Snapshot subject locking');
        $this->locks->acquireProcurements($identities);

        return $this->lockRows($identities);
    }

    /** @param list<ProcurementIdentity> $identities
     * @return list<LockedProcurement>
     *                                 Caller has already acquired sorted identity authorities before its subject tier.
     */
    public function lockRows(array $identities): array
    {
        CallerTransaction::require('Snapshot row locking');
        $rows = [];
        foreach ($identities as $identity) {
            $row = $this->read->findProcurement($identity);
            if ($row !== null) {
                $rows[$row->id] = $row;
            }
        }
        uksort($rows, static fn (int $left, int $right): int => $left <=> $right);
        foreach ($rows as $row) {
            Procurement::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
        }
        $transaction = $this->transactionId();

        return array_map(fn (ProcurementIdentity $identity): LockedProcurement => new LockedProcurement($identity, $this->read->findProcurement($identity), $transaction), $identities);
    }

    private function transactionId(): string
    {
        return LedgerRow::one('SELECT txid_current()::text AS id')?->string('id')
            ?? throw new \LogicException('Missing transaction identity.');
    }

    /** No transaction nesting, advisory acquisition or external I/O after the work fence. */
    public function persistLocked(ProcurementSnapshotData $data, LockedProcurement $locked,
        ?ProcessUuid $processRunId = null, ?Carbon $observedAt = null): Procurement
    {
        CallerTransaction::require('Snapshot publication');
        if ($locked->transactionId !== $this->transactionId()
            || $data->identity->toArray() !== $locked->identity->source->toArray()
            || $data->registryId !== $locked->identity->registryId) {
            throw new \LogicException('Snapshot lock binding mismatch.');
        }
        $this->extensions->validate($data->identity->platform, $data->extension);
        $procurement = $this->ingest->ingestLocked($data, $locked->identity, $locked->procurement, $processRunId, $observedAt);
        $latest = $this->read->findLatestRevision($procurement->id);
        $hash = hash('sha256', json_encode($data->toSnapshotArray($procurement->status->value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($latest?->snapshot_hash === $hash) {
            $this->extensions->persist($data->identity->platform, $procurement->id, $data->extension);
        }

        return $procurement;
    }
}
