<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Repair lease workflow for durable document blobs. No object I/O here. */
final readonly class ArtifactRepairRepository
{
    public function __construct(private StorageCapacityRepository $capacity, private ProcessTransaction $transactions) {}

    /** Durable repair ownership survives connection loss; an uncertain writer never loses its pin. */
    public function beginRepair(BlobReference $blob): bool
    {
        return $this->claimRepair($blob) !== null;
    }

    public function beginRemoteRepair(BlobReference $blob, StorageReservationRequest $source): ?string
    {
        return $this->claimRepair($blob, source: $source);
    }

    /** A confirmed completed write can release its extra charge even if read-back is unavailable. */
    public function finishRepair(BlobReference $blob): void
    {
        if (! $this->completeRepair($blob)) {
            throw new ArtifactUnavailable('Canonical repair ownership changed.');
        }
    }

    public function finishRemoteRepair(BlobReference $blob, string $claim): bool
    {
        return $this->completeRepair($blob, claim: $claim);
    }

    public function remoteRepairClaim(BlobReference $blob): ?LedgerRow
    {
        $bindings = [$blob->id->value, $blob->domainId->value, $blob->disk, $blob->objectKey];
        $held = "id=? AND domain_id=? AND disk=? AND object_key=? AND status='writing'";
        $claim = LedgerRow::one("SELECT repair_attempt_id, repair_claimed_at FROM prc_storage_reservations WHERE {$held}", $bindings);
        if ($claim !== null && $claim->nullableString('repair_claimed_at') === null) {
            // Legacy remote pins predate owner tracking. Persist when recovery first observed the pin.
            return LedgerRow::one("UPDATE prc_storage_reservations SET repair_claimed_at=clock_timestamp()
                WHERE {$held} AND repair_claimed_at IS NULL RETURNING repair_attempt_id, repair_claimed_at", $bindings);
        }

        return $claim;
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

        return array_map(ArtifactRepository::reference(...), $rows);
    }

    private function claimRepair(BlobReference $blob, ?StorageReservationRequest $source = null): ?string
    {
        return $this->transactions->run(function () use ($blob, $source): ?string {
            $this->capacity->lockDomains([$blob->domainId]);
            $reservation = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$blob->id->value]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($row === null) {
                return null;
            }
            if (! $this->matchesReservation($reservation, $row, $blob, 'transferred')) {
                throw new ArtifactUnavailable('Canonical repair requires an unclaimed durable reservation.');
            }
            $domain = LedgerRow::one('SELECT * FROM prc_storage_domains WHERE id=?', [$blob->domainId->value]);
            $reserved = LedgerRow::one("SELECT COALESCE(SUM(byte_upper_bound),0)::bigint AS bytes FROM prc_storage_reservations
                WHERE domain_id=? AND status IN ('reserved','writing','staged','cleaning')", [$blob->domainId->value])?->int('bytes') ?? 0;
            if ($domain === null || $reservation->int('byte_upper_bound') > $domain->int('capacity_bytes') - $domain->int('accounted_durable_bytes') - $reserved) {
                throw new ArtifactUnavailable('Canonical repair storage capacity unavailable.');
            }
            if ($source !== null && LedgerRow::one("SELECT 1 FROM prc_process_attempts a
                JOIN prc_process_work w ON w.id=a.work_id WHERE a.id=? AND w.id=? AND a.generation=?
                AND a.finished_at IS NULL AND w.generation=a.generation AND w.owner_token=?
                AND w.state='running' AND w.lease_until > clock_timestamp() FOR SHARE OF w",
                [$source->attemptId?->value, $source->workId?->value, $source->generation, $source->ownerToken->value]) === null) {
                throw new ArtifactUnavailable('Canonical repair requires a live writer lease.');
            }

            return LedgerRow::one("UPDATE prc_storage_reservations
                SET status='writing', repair_attempt_id=?, repair_claimed_at=clock_timestamp()
                WHERE id=? RETURNING repair_claimed_at::text AS claim",
                [$source?->attemptId?->value, $blob->id->value])?->string('claim');
        });
    }

    private function completeRepair(BlobReference $blob, ?string $claim = null): bool
    {
        return $this->transactions->run(function () use ($blob, $claim): bool {
            $this->capacity->lockDomains([$blob->domainId]);
            $reservation = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=? FOR UPDATE', [$blob->id->value]);
            $row = LedgerRow::one("SELECT *,encode(content_hash,'hex') AS hash FROM prc_document_blobs WHERE id=? FOR UPDATE", [$blob->id->value]);
            if ($row === null || ! $this->matchesReservation($reservation, $row, $blob, 'writing')
                || ($claim === null && $reservation->nullableString('repair_attempt_id') !== null)
                || ($claim !== null && $reservation->nullableString('repair_claimed_at') !== $claim)) {
                return false;
            }
            DB::update("UPDATE prc_storage_reservations SET status='transferred', repair_attempt_id=NULL, repair_claimed_at=NULL WHERE id=?", [$blob->id->value]);

            return true;
        });
    }

    /**
     * @phpstan-assert-if-true LedgerRow $reservation
     */
    private function matchesReservation(?LedgerRow $reservation, LedgerRow $blobRow, BlobReference $blob, string $expectedStatus): bool
    {
        return $reservation !== null
            && ArtifactRepository::reference($blobRow) == $blob
            && $blobRow->nullableString('deletion_marked_at') === null
            && $reservation->string('status') === $expectedStatus
            && $reservation->string('domain_id') === $blob->domainId->value
            && $reservation->string('disk') === $blob->disk
            && $reservation->string('object_key') === $blob->objectKey;
    }
}
