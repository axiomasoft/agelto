<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Dto\Procurement\Document\Storage\BlobReference;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\PrefixFailure;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Support\LockedPrefix;
use App\Services\Procurement\Document\PreparePolicy;
use App\Services\Procurement\Document\PrepareWorkspace;
use App\Services\Procurement\Process\Execution\SubjectLockResolver;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Str;

/** Validating model output prepares an artifact, using the existing bounded prepare storage domains. */
final readonly class OutputStorageRepository
{
    public function __construct(private AuthorityRepository $authority, private StorageCapacityRepository $capacity,
        private ArtifactRepository $artifacts, private ProcessTransaction $transactions, private SubjectLockResolver $subjects,
        private PreparePolicy $policy) {}

    /** @return array{StorageReservationRequest,StorageReservationRequest} */
    public function reserve(ExecutionLease $lease, int $bytes): array
    {
        if ($lease->stage !== StageCode::Extraction) {
            throw new \InvalidArgumentException('Validated chunk storage requires an extraction owner.');
        }
        $durable = $this->domain($this->policy->durableDomain);
        $temporary = $this->domain($this->policy->temporaryDomain);
        $output = new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $durable, $lease->token, $lease->generation,
            $bytes, StoragePhase::Prepare, $this->policy->disk, $lease->workId->value, $lease->attemptId);
        $spool = new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $temporary, $lease->token, $lease->generation,
            $bytes + PrepareWorkspace::SPOOL_PROOF_BYTES, StoragePhase::Prepare, $this->policy->temporaryDisk, $lease->workId->value, $lease->attemptId);
        try {
            for ($reload = 0; $reload < 3; $reload++) {
                $snapshot = $this->snapshot($lease);
                $saved = $this->transactions->run(function () use ($snapshot, $lease, $output, $spool): bool {
                    $prefix = $this->authority->lockPrefix($snapshot, false, storageDomains: [$output->domainId, $spool->domainId]);
                    if ($prefix === PrefixFailure::SnapshotChanged) {
                        return false;
                    }
                    $this->guard($snapshot, $prefix, $lease, true);
                    if (! $this->capacity->reserveLocked($output) || ! $this->capacity->reserveLocked($spool)) {
                        throw new \OverflowException('Output storage capacity exhausted.');
                    }

                    return true;
                });
                if ($saved) {
                    return [$output, $spool];
                }
            }
        } catch (\OverflowException $failure) {
            throw new DependencyUnavailable('storage_capacity', previous: $failure);
        }

        throw new DependencyUnavailable('storage');
    }

    /** Immutable bytes/accounting may precede chunk acceptance; the work reservation pins the orphan. */
    public function acquire(ExecutionLease $lease, VerifiedArtifact $artifact): BlobReference
    {
        $owner = $artifact->reservation;
        if ($lease->stage !== StageCode::Extraction || $owner->workId != $lease->workId->value || $owner->attemptId != $lease->attemptId
            || $owner->generation !== $lease->generation || $owner->ownerToken != $lease->token) {
            throw new \InvalidArgumentException('Validated artifact belongs to another execution owner.');
        }
        for ($reload = 0; $reload < 3; $reload++) {
            $snapshot = $this->snapshot($lease);
            $result = $this->transactions->run(function () use ($lease, $artifact, $snapshot): BlobReference|PrefixFailure {
                $prefix = $this->authority->lockPrefix($snapshot, false);
                if ($prefix === PrefixFailure::SnapshotChanged) {
                    return $prefix;
                }
                $this->guard($snapshot, $prefix, $lease, false);

                return $this->artifacts->acquireLocked($artifact);
            });
            if ($result instanceof BlobReference) {
                return $result;
            }
        }

        throw new DependencyUnavailable('storage');
    }

    private function snapshot(ExecutionLease $lease): AuthoritySnapshot
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new LeaseLost('Output storage authority unavailable.');
        }

        return $snapshot;
    }

    private function guard(AuthoritySnapshot $snapshot, LockedPrefix|PrefixFailure $prefix, ExecutionLease $lease, bool $live): void
    {
        if (! $prefix instanceof LockedPrefix || ! $prefix->intentActive || $prefix->work->string('state') !== 'running'
            || ($live && $prefix->work->bool('lease_expired')) || $prefix->work->int('generation') !== $lease->generation
            || $prefix->work->nullableString('owner_token') !== $lease->token->value
            || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
            || $prefix->group?->nullableString('attempt_id') !== $lease->attemptId->value
            || $this->subjects->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            throw new LeaseLost('Output storage owner/input changed.');
        }
    }

    private function domain(string $key): ProcessUuid
    {
        $row = LedgerRow::one('SELECT id FROM prc_storage_domains WHERE domain_key=?', [$key]);
        if ($row === null) {
            throw new DependencyUnavailable('storage');
        }

        return new ProcessUuid($row->string('id'));
    }
}
