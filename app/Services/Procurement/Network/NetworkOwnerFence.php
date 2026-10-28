<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\NetworkOwnerState;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\SubjectLockResolver;

/** Reuses Process authority; standalone operations lock their immutable control closure first. */
final readonly class NetworkOwnerFence
{
    public function __construct(private AuthorityRepository $authority, private SubjectLockResolver $subjects) {}

    public function snapshot(ExecutionLease|OutboundOperationHandle $owner): ?AuthoritySnapshot
    {
        if ($owner instanceof OutboundOperationHandle) {
            return null;
        }
        $snapshot = $this->authority->readAuthoritySnapshot($owner->workId, $owner->handlerVersion);

        return $snapshot instanceof AuthoritySnapshot ? $snapshot : null;
    }

    public function lock(ExecutionLease|OutboundOperationHandle $owner, ?AuthoritySnapshot $snapshot): NetworkOwnerState|TransportBlocked
    {
        CallerTransaction::require('Network owner prefix');
        if ($owner instanceof ExecutionLease) {
            if ($snapshot === null) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false);
            if ($prefix instanceof PrefixFailure) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            if (! $prefix->controlsEnabled) {
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

            return new NetworkOwnerState(DemandPurpose::Ordinary, null, null, $versions, $work->nullableInt('platform_id'));
        }
        $controls = LedgerRow::all(
            'SELECT c.id, c.desired_mode FROM prc_control_scopes c
             JOIN prc_outbound_operation_controls oc ON oc.control_scope_id = c.id
             WHERE oc.operation_id = ? ORDER BY c.scope_key COLLATE "C" FOR SHARE OF c', [$owner->id->value],
        );
        $operation = LedgerRow::one(
            'SELECT kind, platform_id, generation, owner_token, state, probe_token, deadline_at::text AS deadline_at,
                    lease_until > clock_timestamp() AND deadline_at > clock_timestamp() AS live
             FROM prc_outbound_operations WHERE id = ? FOR UPDATE', [$owner->id->value],
        );
        if ($operation === null || $controls === [] || ! $operation->bool('live') || $operation->string('state') !== 'open'
            || $operation->int('generation') !== $owner->generation || $operation->string('owner_token') !== $owner->ownerToken->value
            || $operation->string('kind') !== $owner->kind->value) {
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

        return new NetworkOwnerState($purpose, $operation->nullableString('probe_token'), new \DateTimeImmutable($operation->string('deadline_at')), platformId: $operation->nullableInt('platform_id'));
    }
}
