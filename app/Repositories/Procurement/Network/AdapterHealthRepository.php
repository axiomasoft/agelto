<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\AdapterValidationReceipt;
use App\Dto\Procurement\Network\NetworkChangeResult;
use App\Dto\Procurement\Network\NetworkOwnerState;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\ActorKind;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Services\Procurement\Network\AdapterHealthPolicy;
use App\Services\Procurement\Network\NetworkOwnerFence;
use Illuminate\Support\Facades\DB;

/** Canonical parser/contract authority. Physical transport health has independent evidence. */
final readonly class AdapterHealthRepository
{
    public function __construct(
        private NetworkOwnerFence $owners,
        private AdapterHealthPolicy $policy,
        private IncidentRepository $incidents,
        private TransitionRepository $transitions,
    ) {}

    /** Only explicit catalog synchronization creates a contract version; ensure never resets it. */
    public function ensure(AdapterContractRef $contract): void
    {
        CallerTransaction::require('Adapter contract authority ensure');
        $platform = LedgerRow::one('SELECT id FROM prc_platforms WHERE code = ?', [$contract->platformCode])
            ?? throw new \InvalidArgumentException('Adapter contract platform is not installed.');
        DB::insert("INSERT INTO prc_adapter_health
            (id, platform_id, capability, adapter_version, contract_version, state, health_epoch, state_version, recent_openings)
            VALUES (?, ?, ?, ?, ?, 'validating', 1, 1, 0)
            ON CONFLICT (platform_id, capability, adapter_version, contract_version) DO NOTHING",
            [ProcessUuid::random()->value, $platform->int('id'), $contract->capability, $contract->adapterVersion, $contract->contractVersion]);
    }

    public function find(AdapterContractRef $contract): ?LedgerRow
    {
        return LedgerRow::one('SELECT h.* FROM prc_adapter_health h JOIN prc_platforms p ON p.id = h.platform_id
            WHERE p.code = ? AND h.capability = ? AND h.adapter_version = ? AND h.contract_version = ?',
            [$contract->platformCode, $contract->capability, $contract->adapterVersion, $contract->contractVersion]);
    }

    /** Caller owns its prefix before this row; counts never lock another operation owner. */
    public function admit(AdapterContractRef $contract, OutboundExecutionContext|ExecutionContext $context): AdapterValidationClaim|TransportBlocked|TransportDeferred
    {
        CallerTransaction::require('Full adapter validation admission');
        $owner = $this->owners->lock($context->lease(), $this->owners->snapshot($context->lease()));
        if ($owner instanceof TransportBlocked) {
            return $owner;
        }
        $denied = static fn (): TransportBlocked => new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        $current = AdapterHealthSqlHelper::findActiveContractTarget($owner->platformId, $contract);
        if ($current === null || ($owner->operationId === null && $owner->attemptId === null)) {
            return $denied();
        }
        $row = LedgerRow::one('SELECT h.* FROM prc_adapter_health h
            WHERE h.platform_id = ? AND h.capability = ? AND h.adapter_version = ? AND h.contract_version = ? FOR UPDATE',
            [$owner->platformId, $contract->capability, $contract->adapterVersion, $contract->contractVersion]);
        if ($row === null) {
            return $denied();
        }
        if ($row->string('state') === 'held') {
            return new TransportBlocked(NetworkBlockedReason::AdapterHeld);
        }
        $epoch = $row->int('health_epoch');
        $token = $row->nullableString('probe_token');
        $holder = AdapterHealthSqlHelper::activeProbeHolder($row->string('id'), $epoch, $token);
        $now = new \DateTimeImmutable((LedgerRow::one('SELECT clock_timestamp()::text AS now')
            ?? throw new \LogicException('Database clock is unavailable.'))->string('now'));
        $wait = static fn (): TransportDeferred => new TransportDeferred(
            NetworkDeferralReason::CapacityWait,
            new RetryAt($now->modify('+1 second')),
        );
        $ownerId = $context instanceof ExecutionContext ? $context->lease()->attemptId : $context->lease()->id;
        if ($holder !== null && ($context instanceof ExecutionContext || $holder->string('id') !== $ownerId->value)) {
            return $wait();
        }
        if ($token !== null && $holder === null) {
            $epoch++;
        }
        if ($owner->adapterHealthId !== null && ($owner->adapterHealthId->value !== $row->string('id') || $owner->adapterHealthEpoch !== $epoch)) {
            return $denied();
        }
        $canary = in_array($row->string('state'), ['validating', 'suspect'], true) || $owner->purpose === DemandPurpose::Probe;
        if ($canary && ($context instanceof ExecutionContext || $owner->purpose !== DemandPurpose::Probe || $owner->probeToken !== $context->lease()->ownerToken->value)) {
            return $denied();
        }
        $capacity = $canary ? 1 : $this->policy->capacity(AdapterHealthSqlHelper::countAcceptedObservations('prc_adapter_health:'.$row->string('id')));
        if (AdapterHealthSqlHelper::countActiveValidationOwners($row->string('id'), $epoch, $ownerId->value) >= $capacity) {
            return $wait();
        }
        if (LedgerRow::one('SELECT observation_id FROM prc_observations WHERE observation_id = ?', [$ownerId->value]) !== null) {
            return $denied();
        }
        if (AdapterHealthSqlHelper::attachValidationOwner($context, $row->string('id'), $epoch, $ownerId) !== 1) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        if ($canary || $token !== null) {
            DB::update('UPDATE prc_adapter_health SET health_epoch = ?, state_version = state_version + 1,
                probe_token = ?, probe_until = ? WHERE id = ?',
                [$epoch, $canary ? $context->lease()->ownerToken->value : null,
                    $canary ? $context->lease()->deadlineAt : null, $row->string('id')]);
        }

        return new AdapterValidationClaim(
            new ProcessUuid($row->string('id')),
            $epoch, $contract, $ownerId, $canary,
            $context instanceof ExecutionContext ? AdapterValidationOwnerKind::WorkAttempt : AdapterValidationOwnerKind::OutboundOperation,
        );
    }

    /**
     * @param  AdapterInspection<mixed>  $inspection
     */
    public function observe(OutboundExecutionContext|ExecutionContext $context, ContentHash $input, AdapterInspection $inspection): AdapterValidationReceipt
    {
        CallerTransaction::require('Full adapter validation outcome');
        $claim = $context->adapterClaim() ?? throw new \LogicException('Validation has no admitted contract.');
        $owner = $this->owners->lock($context->lease(), $this->owners->snapshot($context->lease()));
        $row = LedgerRow::one('SELECT * FROM prc_adapter_health WHERE id = ? FOR UPDATE', [$claim->healthId->value])
            ?? throw new \LogicException('Adapter authority disappeared.');
        $requests = $context->successfulRequestIds();
        $previous = LedgerRow::one("SELECT encode(input_hash, 'hex') AS hash, details, stale FROM prc_observations WHERE observation_id = ?", [$claim->ownerId->value]);
        if ($previous !== null) {
            return AdapterHealthSqlHelper::verifyDuplicateObservation($previous, $claim, $input, $inspection, $requests);
        }
        $current = AdapterHealthSqlHelper::findActiveContractTarget($row->int('platform_id'), $claim->contract);
        $now = new \DateTimeImmutable((LedgerRow::one('SELECT clock_timestamp()::text AS now')
            ?? throw new \LogicException('Database clock is unavailable.'))->string('now'));
        $stale = AdapterHealthSqlHelper::isStaleObservation($current, $owner, $claim, $row, $context, $now);
        AdapterHealthSqlHelper::assertRequestProvenance($claim, $context, $requests);
        $key = $claim->epochKey();
        $version = $row->int('state_version') + 1;
        $applied = AdapterHealthSqlHelper::recordObservation($claim, $context, $input, $inspection, $requests, $stale, $version, $this->policy->version, $now);
        if (! $stale && $applied === 1) {
            $accepted = $inspection->verdict === AdapterVerdict::Accepted;
            $partial = $inspection->verdict === AdapterVerdict::Partial;
            $held = $inspection->verdict === AdapterVerdict::ContractFailure
                && ($inspection->knownDefect || AdapterHealthSqlHelper::countContractFailureInputs($key, $inspection->signature?->hex, $this->policy->windowSeconds) >= $this->policy->distinctInputs);
            $state = $accepted ? 'healthy' : ($partial ? $row->string('state') : ($held ? 'held' : 'suspect'));
            $revoke = $held || (! $accepted && ! $partial && $row->string('state') === 'healthy');
            $changed = AdapterHealthSqlHelper::applyObservedHealthState($context, $claim, $state, $version, $revoke, $accepted ? null : $inspection->checkCode, $accepted, $partial, $held, $now);
            if ($changed !== 1) {
                $stale = true;
                DB::update("UPDATE prc_observations SET stale = true, details = jsonb_set(details, '{applied_state_version}', 'null'::jsonb) WHERE observation_id = ?", [$claim->ownerId->value]);
            }
            if ($held && ! $stale) {
                $signature = $inspection->signature ?? throw new \LogicException('Held adapter lacks contract evidence.');
                $this->incidents->open(new ContentHash(hash('sha256', $key.':'.$claim->epoch)),
                    'acquisition:platform:'.$claim->contract->platformCode, 'adapter_contract_held',
                    ['adapter_health_id' => $claim->healthId->value, 'observation_id' => $claim->ownerId->value,
                        'signature_hash' => $signature->hex, 'health_epoch' => $claim->epoch],
                    ProcessUuid::random(), ProcessUuid::random(), ProcessUuid::random());
            }
        }

        return new AdapterValidationReceipt(
            $claim->healthId, $claim->epoch, $claim->contract,
            $input, $claim->ownerId, $claim->ownerId, $requests, $stale, $inspection->verdict, $claim->ownerKind,
        );
    }

    /** Release only this exact full-validator claim; network abandonment is no semantic reset. */
    public function release(OutboundExecutionContext|ExecutionContext $context): void
    {
        CallerTransaction::require('Full validation claim release');
        $claim = $context->adapterClaim();
        if ($claim === null || ! $claim->canary || ! $context instanceof OutboundExecutionContext) {
            return;
        }
        LedgerRow::one('SELECT id FROM prc_outbound_operations WHERE id = ? FOR UPDATE', [$context->lease()->id->value]);
        DB::update('UPDATE prc_adapter_health SET probe_token = NULL, probe_until = NULL, state_version = state_version + 1
            WHERE id = ? AND health_epoch = ? AND probe_token = ?',
            [$claim->healthId->value, $claim->epoch, $context->lease()->ownerToken->value]);
    }

    /** Dead exclusive validator only; an abandoned ordinary owner never changes health epochs. */
    public function reap(int $limit = 100): int
    {
        CallerTransaction::require('Expired adapter claim cleanup');
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid adapter claim cleanup bound.');
        }
        $count = 0;
        $owners = LedgerRow::all("SELECT o.id, o.owner_token, o.adapter_health_id, o.adapter_health_epoch
            FROM prc_outbound_operations o JOIN prc_adapter_health h ON h.id = o.adapter_health_id
                AND h.health_epoch = o.adapter_health_epoch AND h.probe_token = o.owner_token
            WHERE o.state <> 'open' OR o.lease_until <= clock_timestamp() OR o.deadline_at <= clock_timestamp()
            ORDER BY o.id LIMIT ? FOR UPDATE OF o SKIP LOCKED", [$limit]);
        foreach ($owners as $owner) {
            $count += DB::update('UPDATE prc_adapter_health SET health_epoch = health_epoch + 1,
                state_version = state_version + 1, probe_token = NULL, probe_until = NULL
                WHERE id = ? AND health_epoch = ? AND probe_token = ?',
                [$owner->string('adapter_health_id'), $owner->int('adapter_health_epoch'), $owner->string('owner_token')]);
        }

        return $count;
    }

    /** Business mutation uses the same transaction to fence the accepted version and epoch. */
    public function assertReceipt(AdapterValidationReceipt $receipt): void
    {
        CallerTransaction::require('Validated input commit fence');
        LedgerRow::one("SELECT id FROM prc_control_scopes WHERE scope_key = 'system' FOR SHARE")
            ?? throw new \LogicException('Control authority is missing.');
        $row = LedgerRow::one('SELECT h.health_epoch, h.state FROM prc_adapter_health h WHERE h.id = ? FOR SHARE', [$receipt->healthId->value]);
        $fact = AdapterHealthSqlHelper::verifyReceiptFact($receipt);
        if ($receipt->observationId->value !== $receipt->ownerId->value || ! in_array($receipt->verdict, [AdapterVerdict::Accepted, AdapterVerdict::Partial], true)
            || $receipt->stale || $row === null || $row->int('health_epoch') !== $receipt->epoch || $row->string('state') === 'held' || $fact === null
            || LedgerRow::one("SELECT id FROM prc_source_targets WHERE retired_at IS NULL AND capability = ?
                AND endpoint_policy->'adapter_contract' = ?::jsonb LIMIT 1", [$receipt->contract->capability,
                json_encode($receipt->contract->toArray(), JSON_THROW_ON_ERROR)]) === null) {
            throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::AdapterHeld));
        }
    }

    /** Explicit audited reset establishes a new evidence generation by monotonic state_version. */
    public function reset(AdapterContractRef $contract, int $expectedVersion, int $actorId, string $reason): NetworkChangeResult
    {
        CallerTransaction::require('Adapter authority reset');
        if ($actorId < 1 || trim($reason) === '') {
            throw new \InvalidArgumentException('Adapter reset requires an actor and reason.');
        }
        $system = LedgerRow::one("SELECT id, desired_mode, control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")
            ?? throw new \LogicException('Control authority is missing.');
        $identity = $this->find($contract);
        $row = $identity === null ? null : LedgerRow::one('SELECT * FROM prc_adapter_health WHERE id = ? FOR UPDATE', [$identity->string('id')]);
        if ($row === null) {
            return new NetworkChangeResult(NetworkChangeStatus::NotFound, null);
        }
        if ($row->int('state_version') !== $expectedVersion) {
            return new NetworkChangeResult(NetworkChangeStatus::VersionConflict, $row->int('state_version'));
        }
        if ($row->string('state') !== 'held') {
            return new NetworkChangeResult(NetworkChangeStatus::Unchanged, $expectedVersion);
        }
        $version = $expectedVersion + 1;
        $epoch = $row->int('health_epoch') + 1;
        AdapterHealthSqlHelper::recordResetObservation($contract, $row->string('id'), $epoch, $version, $actorId, $reason);
        $controlVersion = $system->int('control_version') + 1;
        DB::update('UPDATE prc_control_scopes SET control_version = ? WHERE id = ?', [$controlVersion, $system->string('id')]);
        $this->transitions->recordControl(ProcessUuid::random(), ProcessUuid::random(), new ProcessUuid($system->string('id')),
            $controlVersion, $system->string('desired_mode'), $system->string('desired_mode'), 'adapter_validation_reset',
            ActorKind::Operator, $actorId, ['reason' => $reason, 'adapter_health_id' => $row->string('id')]);

        return new NetworkChangeResult(NetworkChangeStatus::Applied, $version);
    }

    /** Called after target/target-health locks, before network budgets; catalog decides requiredness. */
    public function lockForSend(LedgerRow $target, OutboundRequestPlan $plan, NetworkOwnerState $owner): LedgerRow|TransportBlocked|null
    {
        CallerTransaction::require('Adapter last-send fence');

        return AdapterHealthSqlHelper::lockAdapterRowForSend($target, $plan, $owner);
    }
}
