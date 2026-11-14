<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Dto\Procurement\Network\NetworkOwnerState;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\NetworkOwnerFence;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Contracts\SqlConnectionParticipant;
use Proxyma\Domain\Execution\BlockedReason;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DemandPurpose;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\LockedTransactionContext;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Execution\OwnerSnapshot;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use WeakMap;

final class ProcessOwnerAuthority implements OwnerAuthority, SqlConnectionParticipant
{
    /**
     * @var WeakMap<OwnerRef, array{
     *     lease: ExecutionLease|OutboundOperationHandle,
     *     provider: ProviderKey|null,
     *     purpose: DemandPurpose|null,
     *     probe: Uuid|null,
     *     snapshots: WeakMap<OwnerSnapshot, array{native: AuthoritySnapshot|null}>,
     *     state?: NetworkOwnerState,
     *     transaction?: LockedTransactionContext
     * }>
     */
    private WeakMap $contexts;

    public function __construct(private readonly NetworkOwnerFence $fence, private readonly ConnectionScope $scope)
    {
        $this->contexts = new WeakMap;
    }

    public function connection(): Connection
    {
        $this->scope->assertSame(DB::connection());

        return $this->scope->connection;
    }

    public function connectionName(): string
    {
        return $this->connection()->getName() ?? throw new \LogicException('Owner authority requires a named connection.');
    }

    public function reference(ExecutionLease|OutboundOperationHandle $lease, ?ProviderKey $provider = null,
        ?DemandPurpose $networkPurpose = null, ?Uuid $probe = null): OwnerRef
    {
        if ($networkPurpose !== null && (! $lease instanceof OutboundOperationHandle || $lease->kind->value !== 'probe'
            || ! in_array($networkPurpose, [DemandPurpose::Diagnostic, DemandPurpose::Probe], true)
            || ($networkPurpose === DemandPurpose::Probe) !== ($probe !== null))) {
            throw new \InvalidArgumentException('Only an owned adapter canary can translate its network purpose.');
        }
        $owner = new OwnerRef(new TenantKey(TenantKey::SYSTEM),
            $lease instanceof ExecutionLease ? 'process_attempt' : 'outbound_operation',
            $lease instanceof ExecutionLease ? $lease->attemptId->value : $lease->id->value,
            $lease instanceof ExecutionLease ? $lease->token->value : $lease->ownerToken->value, $lease->generation);
        /** @var WeakMap<OwnerSnapshot, array{native: AuthoritySnapshot|null}> $snapshots */
        $snapshots = new WeakMap;
        $this->contexts[$owner] = ['lease' => $lease, 'provider' => $provider, 'snapshots' => $snapshots, 'purpose' => $networkPurpose, 'probe' => $probe];

        return $owner;
    }

    public function snapshot(OwnerRef $owner): ?OwnerSnapshot
    {
        $entry = $this->contexts[$owner] ?? null;
        if ($entry === null) {
            return null;
        }
        $this->connection();
        $selector = LedgerRow::one('SELECT generation FROM '.(new NetworkAuthority)->getTable().' WHERE id=?', ['network']);
        if ($selector === null) {
            return null;
        }
        $lease = $entry['lease'];
        $native = $this->fence->snapshot($lease, $entry['provider']);
        if ($lease instanceof ExecutionLease && $native === null) {
            return null;
        }
        $controls = ['network_authority' => $selector->int('generation')];
        foreach ($native->controls ?? [] as $control) {
            $controls[$control->scopeKey] = $control->version;
        }
        $parent = null;
        if ($lease instanceof ExecutionLease) {
            $attachment = LedgerRow::one('SELECT adapter_health_id, adapter_health_epoch FROM prc_process_attempts WHERE id=?', [$lease->attemptId->value]);
            $inputHash = $lease->inputHash->hex;
        } else {
            $operation = LedgerRow::one("SELECT parent_attempt_id, adapter_health_id, adapter_health_epoch, encode(policy_hash, 'hex') AS policy_hash_hex
                FROM prc_outbound_operations WHERE id=?", [$lease->id->value]);
            if ($operation === null) {
                return null;
            }
            $parent = $operation->nullableString('parent_attempt_id');
            $attachment = $operation;
            $inputHash = $operation->string('policy_hash_hex');
            foreach (LedgerRow::all('SELECT c.scope_key, c.control_version FROM prc_outbound_operation_controls oc
                JOIN prc_control_scopes c ON c.id=oc.control_scope_id WHERE oc.operation_id=?', [$lease->id->value]) as $control) {
                $controls[$control->string('scope_key')] = $control->int('control_version');
            }
        }
        $adapterId = $attachment?->nullableString('adapter_health_id');
        $snapshot = new OwnerSnapshot($owner, $lease instanceof ExecutionLease ? $lease->leaseUntil : min($lease->deadlineAt, $lease->leaseUntil),
            $lease instanceof ExecutionLease ? $lease->inputRevision : 1,
            new ContentHash($inputHash),
            $controls, parentAttemptId: $parent === null ? null : new Uuid($parent),
            adapterHealthId: $adapterId === null ? null : new Uuid($adapterId),
            adapterHealthEpoch: $attachment?->nullableInt('adapter_health_epoch'));
        $entry['snapshots'][$snapshot] = ['native' => $native];
        $this->contexts[$owner] = $entry;

        return $snapshot;
    }

    public function validateSnapshot(OwnerRef $owner, OwnerSnapshot $snapshot): bool
    {
        return $owner->matchesIdentityAndToken($snapshot->owner)
            && isset($this->contexts[$owner]['snapshots'][$snapshot]);
    }

    public function lockAndValidate(OwnerRef $owner, OwnerSnapshot $snapshot, LockedTransactionContext $transaction): LockedOwnerState|ExecutionBlocked
    {
        $this->scope->assertActive($transaction);
        $this->connection();
        if (! $this->validateSnapshot($owner, $snapshot)) {
            return new ExecutionBlocked(BlockedReason::OwnerLost);
        }
        $entry = $this->contexts[$owner];
        unset($entry['state'], $entry['transaction']);
        $this->contexts[$owner] = $entry;
        $state = $this->fence->lock($entry['lease'], $entry['snapshots'][$snapshot]['native'], $entry['provider']);
        if ($state instanceof TransportBlocked) {
            return new ExecutionBlocked(match ($state->reason->value) {
                'paused' => BlockedReason::OwnerPaused,
                'configuration_hold' => BlockedReason::ConfigurationHold,
                default => BlockedReason::OwnerLost,
            });
        }
        if ($snapshot->adapterHealthId?->value !== $state->adapterHealthId?->value
            || $snapshot->adapterHealthEpoch !== $state->adapterHealthEpoch
            || $snapshot->deadlineAt <= new \DateTimeImmutable(
                LedgerRow::one('SELECT clock_timestamp()::text AS now')?->string('now') ?? throw new \LogicException('Missing DB clock.'))) {
            return new ExecutionBlocked(BlockedReason::OwnerLost);
        }
        if ($entry['lease'] instanceof OutboundOperationHandle) {
            $operation = LedgerRow::one("SELECT parent_attempt_id, encode(policy_hash, 'hex') AS policy_hash_hex
                FROM prc_outbound_operations WHERE id=?", [$entry['lease']->id->value]);
            $keys = array_map(static fn (LedgerRow $row): string => $row->string('scope_key'),
                LedgerRow::all('SELECT c.scope_key FROM prc_outbound_operation_controls oc
                    JOIN prc_control_scopes c ON c.id=oc.control_scope_id WHERE oc.operation_id=?', [$entry['lease']->id->value]));
            sort($keys, SORT_STRING);
            $expected = array_keys($snapshot->controlVersions);
            $expected = array_values(array_diff($expected, ['network_authority']));
            sort($expected, SORT_STRING);
            if ($operation?->nullableString('parent_attempt_id') !== $snapshot->parentAttemptId?->value
                || $operation?->string('policy_hash_hex') !== $snapshot->inputHash?->hex || $keys !== $expected) {
                return new ExecutionBlocked(BlockedReason::OwnerLost);
            }
        }
        $selector = LedgerRow::one('SELECT authority, generation, paused FROM '.(new NetworkAuthority)->getTable().' WHERE id=? FOR SHARE', ['network']);
        if ($selector === null || $selector->string('authority') !== 'proxyma' || $selector->bool('paused') || $selector->int('generation') !== $snapshot->controlVersions['network_authority']) {
            return new ExecutionBlocked(BlockedReason::ConfigurationHold);
        }
        foreach ($snapshot->controlVersions as $key => $version) {
            if ($key !== 'network_authority' && LedgerRow::one('SELECT control_version FROM prc_control_scopes WHERE scope_key=?', [$key])?->int('control_version') !== $version) {
                return new ExecutionBlocked(BlockedReason::OwnerLost);
            }
        }
        $this->contexts[$owner] = [...$entry, 'state' => $state, 'transaction' => $transaction];

        $defaultPurpose = $entry['lease'] instanceof OutboundOperationHandle && $entry['lease']->kind->value === 'probe' && $state->probeToken !== null
            ? DemandPurpose::Probe : DemandPurpose::from($state->purpose->value);

        return new LockedOwnerState($owner, $entry['purpose'] ?? $defaultPurpose, min($snapshot->deadlineAt, $state->deadlineAt ?? $snapshot->deadlineAt),
            [...$snapshot->controlVersions, ...$state->versions], $entry['purpose'] === null
                ? ($state->probeToken === null ? null : new Uuid($state->probeToken)) : $entry['probe'],
            $state->adapterHealthId === null ? null : new Uuid($state->adapterHealthId->value), $state->adapterHealthEpoch);
    }

    public function networkState(OwnerRef $owner, LockedTransactionContext $transaction): ?NetworkOwnerState
    {
        $this->scope->assertActive($transaction);
        $entry = $this->contexts[$owner] ?? [];

        return ($entry['transaction'] ?? null) === $transaction ? ($entry['state'] ?? null) : null;
    }
}
