<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\DownloadReservations;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageBlocked;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Document\DownloadPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Support\Str;

final readonly class DownloadStorageRepository
{
    public function __construct(private AuthorityRepository $authority, private StorageCapacityRepository $capacity,
        private ProcessTransaction $transactions, private DownloadPolicy $policy, private SubjectLockResolver $subjects) {}

    public function reserve(ExecutionLease $lease): DownloadReservations|StageBlocked|null
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new LeaseLost('Missing download authority.');
        }
        $domain = $this->domain($this->policy->durableDomain);
        $tmp = $this->domain($this->policy->temporaryDomain);
        $reservations = new DownloadReservations(
            new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $domain, $lease->token, $lease->generation,
                $this->policy->maxBytes, StoragePhase::Download, $this->policy->disk, $lease->workId->value, $lease->attemptId),
            // cURL sink + transport artifact + verified upload spool; conservative combined ceiling.
            new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $tmp, $lease->token, $lease->generation,
                $this->policy->maxBytes * 3, StoragePhase::Download, $this->policy->temporaryDisk, $lease->workId->value, $lease->attemptId));
        try {
            return $this->transactions->run(function () use ($snapshot, $lease, $reservations, $domain, $tmp): DownloadReservations|StageBlocked {
                $prefix = $this->authority->lockPrefix($snapshot, false, publication: new DownloadCapacityLocks($this->capacity, [$domain, $tmp]));
                if ($prefix instanceof PrefixFailure || ! $prefix->intentActive
                    || $prefix->work->string('state') !== 'running' || $prefix->work->bool('lease_expired')
                    || $prefix->work->int('generation') !== $lease->generation || $prefix->work->nullableString('owner_token') !== $lease->token->value
                    || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
                    || $prefix->group?->nullableString('attempt_id') !== $lease->attemptId->value
                    || $this->subjects->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
                    throw new LeaseLost('Download storage reservation lost its execution fence.');
                }
                if (! $prefix->controlsEnabled) {
                    return new StageBlocked(BlockedReason::Paused);
                }
                if (! $prefix->handlerHealthy) {
                    return new StageBlocked(BlockedReason::HandlerHold);
                }
                if (! $this->capacity->reserveLocked($reservations->durable) || ! $this->capacity->reserveLocked($reservations->temporary)) {
                    throw new \OverflowException('Download storage capacity exhausted.');
                }

                return $reservations;
            });
        } catch (\OverflowException) {
            // Roll back BOTH reservations if either domain cannot fit the full ceiling.
            return null;
        }
    }

    /** Logical download and its one signed refresh share a finite physical-send ceiling. */
    public function sends(ExecutionLease $lease): int
    {
        return LedgerRow::one('SELECT count(*) AS sends FROM prc_request_attempts r JOIN prc_network_demands d ON d.id=r.demand_id
            WHERE d.attempt_id=? AND d.work_generation=?', [$lease->attemptId->value, $lease->generation])?->int('sends') ?? 0;
    }

    private function domain(string $key): ProcessUuid
    {
        $row = LedgerRow::one('SELECT id FROM prc_storage_domains WHERE domain_key=?', [$key])
            ?? throw new \LogicException('Download storage domain must be explicitly provisioned.');

        return new ProcessUuid($row->string('id'));
    }
}
