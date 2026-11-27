<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\Control\NetworkOwnerState;
use App\Dto\Procurement\Network\Control\OutboundOperationHandle;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Authority\ControlSnapshot;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Outcomes\PrefixFailure;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Execution\SubjectLockResolver;

/** Reuses Process authority; standalone operations lock their immutable control closure first. */
final readonly class NetworkOwnerFence
{
    public function __construct(private AuthorityRepository $authority, private SubjectLockResolver $subjects) {}

    public function snapshot(ExecutionLease|OutboundOperationHandle $owner, ?ProviderKey $provider = null): ?AuthoritySnapshot
    {
        if ($owner instanceof OutboundOperationHandle) {
            return null;
        }
        $snapshot = $this->authority->readAuthoritySnapshot($owner->workId, $owner->handlerVersion);
        if ($snapshot instanceof AuthoritySnapshot && $provider !== null) {
            $control = LedgerRow::one('SELECT id,scope_key,desired_mode,control_version FROM prc_control_scopes WHERE scope_key=?', ['provider:'.$provider->value]);
            if ($control === null || ! in_array($owner->stage, [StageCode::Screening, StageCode::Extraction], true)) {
                return null;
            }
            $snapshot = new AuthoritySnapshot($snapshot->workId, $snapshot->input, $snapshot->inputRevision, $snapshot->inputHash,
                [...$snapshot->controls, new ControlSnapshot(new ProcessUuid($control->string('id')), $control->string('scope_key'), ControlMode::from($control->string('desired_mode')), $control->int('control_version'))],
                $snapshot->handler, $snapshot->capacity, $snapshot->subjects, $snapshot->admissionGroup, $snapshot->providerOperations);
        }

        return $snapshot instanceof AuthoritySnapshot ? $snapshot : null;
    }

    public function lock(ExecutionLease|OutboundOperationHandle $owner, ?AuthoritySnapshot $snapshot, ?ProviderKey $provider = null,
        bool $allowPausedOwner = false): NetworkOwnerState|TransportBlocked
    {
        CallerTransaction::require('Network owner prefix');
        if ($owner instanceof ExecutionLease) {
            if ($snapshot === null) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            // The work/subjects remain exclusively locked below. A send only reads
            // capacity membership, so shared lane locks preserve the current prefix
            // while independent admitted owners do not serialize behind one lane row.
            $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false, readOnlyLane: true);
            if ($prefix instanceof PrefixFailure) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            if (! $prefix->controlsEnabled && ! $allowPausedOwner) {
                return new TransportBlocked(NetworkBlockedReason::Paused);
            }
            if (! $prefix->handlerHealthy) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $work = $prefix->work;
            if ($work->string('state') !== 'running' || $work->int('generation') !== $owner->generation
                || $work->nullableString('owner_token') !== $owner->token->value || $work->bool('lease_expired')
                || $snapshot->inputHash->hex !== $owner->inputHash->hex || $snapshot->inputRevision !== $owner->inputRevision
                || $snapshot->handler->stage !== $owner->stage || ! $prefix->intentActive
                || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
                || $prefix->group?->nullableString('attempt_id') !== $owner->attemptId->value
                || $this->subjects->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }

            $versions = [];
            foreach ($prefix->subjects as $key => $subject) {
                $versions['subject:'.$key] = $subject->headGeneration;
            }

            $attempt = LedgerRow::one('SELECT adapter_health_id, adapter_health_epoch FROM prc_process_attempts
                WHERE id = ? AND work_id = ? AND generation = ? AND finished_at IS NULL',
                [$owner->attemptId->value, $owner->workId->value->value, $owner->generation]);
            if ($attempt === null) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }

            return new NetworkOwnerState(DemandPurpose::Ordinary, null, null, $versions, $provider === null ? $work->nullableInt('platform_id') : null, $provider,
                adapterHealthId: ($id = $attempt->nullableString('adapter_health_id')) === null ? null : new ProcessUuid($id),
                adapterHealthEpoch: $attempt->nullableInt('adapter_health_epoch'), attemptId: $owner->attemptId);
        }
        $parentId = LedgerRow::one('SELECT parent_attempt_id FROM prc_outbound_operations WHERE id=?', [$owner->id->value])?->nullableString('parent_attempt_id');
        $parent = $parentId === null ? null : $this->parentLease($parentId);
        $parentSnapshot = $parent === null ? null : $this->snapshot($parent);
        if ($parentId !== null && ($parent === null || $parentSnapshot === null)) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $controls = LedgerRow::all(
            'SELECT c.id, c.scope_key, c.desired_mode FROM prc_control_scopes c
             JOIN prc_outbound_operation_controls oc ON oc.control_scope_id = c.id
             WHERE oc.operation_id = ? ORDER BY c.scope_key COLLATE "C" FOR SHARE OF c', [$owner->id->value],
        );
        if ($parent !== null) {
            $lockedKeys = array_map(static fn (LedgerRow $row): string => $row->string('scope_key'), $controls);
            foreach ($parentSnapshot->controls as $scope) {
                if (! in_array($scope->scopeKey, $lockedKeys, true)) {
                    // Never acquire a new earlier-tier control after the composite prefix starts.
                    return new TransportBlocked(NetworkBlockedReason::OwnerLost);
                }
            }
            $parentState = $this->lock($parent, $parentSnapshot);
            if ($parentState instanceof TransportBlocked) {
                return $parentState;
            }
        }
        $operation = LedgerRow::one(
            'SELECT kind, platform_id, provider_key, adapter_health_id, adapter_health_epoch, parent_attempt_id, generation, owner_token, state, probe_token, deadline_at::text AS deadline_at,
                    lease_until > clock_timestamp() AND deadline_at > clock_timestamp() AS live
             FROM prc_outbound_operations WHERE id = ? FOR UPDATE', [$owner->id->value],
        );
        if ($operation === null || $controls === [] || ! $operation->bool('live') || $operation->string('state') !== 'open'
            || $operation->int('generation') !== $owner->generation || $operation->string('owner_token') !== $owner->ownerToken->value
            || $operation->string('kind') !== $owner->kind->value || $operation->nullableString('parent_attempt_id') !== $parentId) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        foreach ($controls as $control) {
            if ($control->string('desired_mode') !== 'enabled') {
                return new TransportBlocked(NetworkBlockedReason::Paused);
            }
        }
        $purpose = match ($owner->kind->value) {
            'probe' => DemandPurpose::Probe,
            'diagnostic' => DemandPurpose::Diagnostic,
            default => DemandPurpose::Ordinary,
        };

        return new NetworkOwnerState($purpose, $operation->nullableString('probe_token'), new \DateTimeImmutable($operation->string('deadline_at')), platformId: $operation->nullableInt('platform_id'), providerKey: $operation->nullableString('provider_key') === null ? null : new ProviderKey($operation->string('provider_key')),
            operationId: $owner->id, adapterHealthId: ($adapterId = $operation->nullableString('adapter_health_id')) === null ? null : new ProcessUuid($adapterId),
            adapterHealthEpoch: $operation->nullableInt('adapter_health_epoch'));
    }

    /** Unlocked immutable attempt linkage; lock() rechecks the complete live parent prefix. */
    private function parentLease(string $attemptId): ?ExecutionLease
    {
        $row = LedgerRow::one("SELECT a.work_id,a.generation,a.handler_version,w.owner_token,w.lease_until,
            w.input_revision,encode(w.input_hash,'hex') AS input_hash,w.checkpoint_seq,w.stage
            FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id AND w.generation=a.generation
            WHERE a.id=? AND a.finished_at IS NULL AND w.state='running' AND w.owner_token IS NOT NULL", [$attemptId]);

        return $row === null ? null : new ExecutionLease(new WorkId(new ProcessUuid($row->string('work_id'))),
            new ProcessUuid($attemptId), $row->int('generation'), new ProcessUuid($row->string('owner_token')),
            new \DateTimeImmutable($row->string('lease_until')), $row->int('input_revision'), new ContentHash($row->string('input_hash')),
            $row->int('checkpoint_seq'), null, StageCode::from($row->string('stage')), $row->string('handler_version'));
    }
}
