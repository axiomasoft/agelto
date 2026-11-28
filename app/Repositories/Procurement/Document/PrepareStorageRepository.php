<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Document\Prepare\PrepareReservations;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\PrefixFailure;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Document\PrepareWorkspace;
use App\Services\Procurement\Process\Execution\SubjectLockResolver;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Str;

final readonly class PrepareStorageRepository
{
    public function __construct(private AuthorityRepository $authority, private StorageCapacityRepository $capacity,
        private ProcessTransaction $transactions, private PreparePolicy $policy, private SubjectLockResolver $subjects) {}

    public function reserve(ExecutionLease $lease): PrepareReservations|StageBlocked|null
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new LeaseLost('Missing prepare authority.');
        }
        $domain = $this->domain($this->policy->durableDomain);
        $tmp = $this->domain($this->policy->temporaryDomain);
        $reservations = new PrepareReservations(
            new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $domain, $lease->token, $lease->generation,
                $this->policy->artifactBytes, StoragePhase::Prepare, $this->policy->disk, $lease->workId->value, $lease->attemptId),
            // Isolated upload spool; workspace has its own independent charge.
            new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $tmp, $lease->token, $lease->generation,
                $this->policy->artifactBytes + PrepareWorkspace::SPOOL_PROOF_BYTES, StoragePhase::Prepare, $this->policy->temporaryDisk, $lease->workId->value, $lease->attemptId),
            new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $tmp, $lease->token, $lease->generation,
                $this->policy->tmpBytes, StoragePhase::Prepare, $this->policy->temporaryDisk, $lease->workId->value, $lease->attemptId));
        try {
            return $this->transactions->run(function () use ($snapshot, $lease, $reservations, $domain, $tmp): PrepareReservations|StageBlocked {
                $prefix = $this->authority->lockPrefix($snapshot, false, publication: new DownloadCapacityLocks($this->capacity, [$domain, $tmp]));
                if ($prefix instanceof PrefixFailure || ! $prefix->intentActive
                    || $prefix->work->string('state') !== 'running' || $prefix->work->bool('lease_expired')
                    || $prefix->work->int('generation') !== $lease->generation || $prefix->work->nullableString('owner_token') !== $lease->token->value
                    || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
                    || $prefix->group?->nullableString('attempt_id') !== $lease->attemptId->value
                    || $this->subjects->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
                    throw new LeaseLost('Prepare storage reservation lost its execution fence.');
                }
                if (! $prefix->controlsEnabled) {
                    return new StageBlocked(BlockedReason::Paused);
                }
                if (! $prefix->handlerHealthy) {
                    return new StageBlocked(BlockedReason::HandlerHold);
                }
                if (! $this->capacity->reserveLocked($reservations->output) || ! $this->capacity->reserveLocked($reservations->spool) || ! $this->capacity->reserveLocked($reservations->workspace)) {
                    throw new \OverflowException('Prepare storage capacity exhausted.');
                }

                return $reservations;
            });
        } catch (\OverflowException) {
            // Roll back BOTH reservations if either domain cannot fit the full ceiling.
            return null;
        }
    }

    private function domain(string $key): ProcessUuid
    {
        $row = LedgerRow::one('SELECT id FROM prc_storage_domains WHERE domain_key=?', [$key])
            ?? throw new \LogicException('Prepare storage domain must be explicitly provisioned.');

        return new ProcessUuid($row->string('id'));
    }
}
