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
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
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
use Illuminate\Support\Str;

/** Canonical parser/contract authority. Physical transport health has independent evidence. */
final readonly class AdapterHealthRepository
{
    public function __construct(private NetworkOwnerFence $owners,
        private AdapterHealthPolicy $policy,
        private IncidentRepository $incidents,
        private TransitionRepository $transitions) {}

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
            [(string) Str::uuid(), $platform->int('id'), $contract->capability, $contract->adapterVersion, $contract->contractVersion]);
    }

    public function find(AdapterContractRef $contract): ?LedgerRow
    {
        return LedgerRow::one('SELECT h.* FROM prc_adapter_health h JOIN prc_platforms p ON p.id = h.platform_id
            WHERE p.code = ? AND h.capability = ? AND h.adapter_version = ? AND h.contract_version = ?',
            [$contract->platformCode, $contract->capability, $contract->adapterVersion, $contract->contractVersion]);
    }

    /** Caller owns its prefix before this row; counts never lock another operation owner. */
    public function admit(AdapterContractRef $contract, OutboundExecutionContext $context): AdapterValidationClaim|TransportBlocked|TransportDeferred
    {
        CallerTransaction::require('Full adapter validation admission');
        LedgerRow::one("SELECT id FROM prc_control_scopes WHERE scope_key = 'system' FOR SHARE")
            ?? throw new \LogicException('Control authority is missing.');
        $owner = $this->owners->lock($context->lease(), null);
        if ($owner instanceof TransportBlocked) {
            return $owner;
        }
        $denied = static fn (): TransportBlocked => new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        $current = LedgerRow::one("SELECT t.id FROM prc_source_targets t JOIN prc_platforms p ON p.id = t.platform_id
            WHERE t.platform_id = ? AND p.code = ? AND t.capability = ? AND t.retired_at IS NULL
                AND t.endpoint_policy_schema_version = 2 AND t.endpoint_policy->'adapter_contract' = ?::jsonb LIMIT 1",
            [$owner->platformId, $contract->platformCode, $contract->capability, json_encode($contract->toArray(), JSON_THROW_ON_ERROR)]);
        if ($current === null || $owner->operationId === null) {
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
        $holder = $token === null ? null : LedgerRow::one("SELECT id FROM prc_outbound_operations
            WHERE adapter_health_id = ? AND adapter_health_epoch = ? AND owner_token = ? AND state = 'open'
                AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp() LIMIT 1",
            [$row->string('id'), $epoch, $token]);
        $now = HealthRepository::now();
        $wait = static fn (): TransportDeferred => new TransportDeferred(
            NetworkDeferralReason::CapacityWait,
            new RetryAt($now->modify('+1 second')));
        if ($holder !== null && $holder->string('id') !== $context->lease()->id->value) {
            return $wait();
        }
        if ($token !== null && $holder === null) {
            // A dead exclusive claim has no other admitted owners; revoke its late result.
            $epoch++;
        }
        if ($owner->adapterHealthId !== null && ($owner->adapterHealthId->value !== $row->string('id') || $owner->adapterHealthEpoch !== $epoch)) {
            return $denied();
        }
        $canary = in_array($row->string('state'), ['validating', 'suspect'], true) || $owner->purpose === DemandPurpose::Probe;
        if ($canary && ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken !== $context->lease()->ownerToken->value)) {
            return $denied();
        }
        // Epoch fences new application. Explicit reset/rejection bounds semantic history;
        // a dead network owner must not erase already accepted validation evidence.
        $key = 'prc_adapter_health:'.$row->string('id');
        $accepted = LedgerRow::one("SELECT count(*)::bigint AS n
            FROM prc_observations o JOIN prc_observation_applications a ON a.observation_id = o.observation_id
            WHERE a.projection_key = ? AND NOT o.stale AND o.kind = 'adapter_validation'
                AND o.details->>'verdict' = 'accepted'
                AND (o.details->>'applied_state_version')::bigint > COALESCE((
                    SELECT max((r.details->>'applied_state_version')::bigint)
                    FROM prc_observations r JOIN prc_observation_applications ra ON ra.observation_id = r.observation_id
                    WHERE ra.projection_key = ? AND NOT r.stale AND (r.kind = 'adapter_validation_reset'
                        OR (r.kind = 'adapter_validation' AND r.details->>'verdict' IN ('contract_failure', 'handler_fault')))
                ), 0)", [$key, $key]);
        $capacity = $canary ? 1 : $this->policy->capacity($accepted?->int('n') ?? 0);
        $active = LedgerRow::one("SELECT count(*)::bigint AS n FROM prc_outbound_operations
            WHERE adapter_health_id = ? AND adapter_health_epoch = ? AND id <> ? AND state = 'open'
                AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$row->string('id'), $epoch, $context->lease()->id->value]);
        if (($active?->int('n') ?? 0) >= $capacity) {
            return $wait();
        }
        if (LedgerRow::one('SELECT observation_id FROM prc_observations WHERE observation_id = ?', [$context->lease()->id->value]) !== null) {
            return $denied();
        }
        $attached = DB::update("UPDATE prc_outbound_operations SET adapter_health_id = ?, adapter_health_epoch = ?
            WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'open'
                AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$row->string('id'), $epoch, $context->lease()->id->value, $context->lease()->generation, $context->lease()->ownerToken->value]);
        if ($attached !== 1) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        if ($canary || $token !== null) {
            DB::update('UPDATE prc_adapter_health SET health_epoch = ?, state_version = state_version + 1,
                probe_token = ?, probe_until = ? WHERE id = ?',
                [$epoch, $canary ? $context->lease()->ownerToken->value : null,
                    $canary ? $context->lease()->deadlineAt : null, $row->string('id')]);
        }

        return new AdapterValidationClaim(new ProcessUuid($row->string('id')),
            $epoch, $contract, $context->lease()->id, $canary);
    }

    /**
     * One immutable full-validator fact, never a per-tab HTTP result. The caller owns the
     * transaction; late facts are retained as stale and cannot update the authority.
     *
     * @param  AdapterInspection<mixed>  $inspection
     */
    public function observe(OutboundExecutionContext $context,
        ContentHash $input, AdapterInspection $inspection): AdapterValidationReceipt
    {
        CallerTransaction::require('Full adapter validation outcome');
        LedgerRow::one("SELECT id FROM prc_control_scopes WHERE scope_key = 'system' FOR SHARE")
            ?? throw new \LogicException('Control authority is missing.');
        $claim = $context->adapterClaim() ?? throw new \LogicException('Validation has no admitted contract.');
        $owner = $this->owners->lock($context->lease(), null);
        $row = LedgerRow::one('SELECT * FROM prc_adapter_health WHERE id = ? FOR UPDATE', [$claim->healthId->value])
            ?? throw new \LogicException('Adapter authority disappeared.');
        $previous = LedgerRow::one("SELECT encode(input_hash, 'hex') AS hash, details, stale FROM prc_observations WHERE observation_id = ?", [$claim->operationId->value]);
        if ($previous !== null) {
            $fact = json_decode($previous->string('details'), true, flags: JSON_THROW_ON_ERROR);
            $requests = $context->successfulRequestIds();
            if (! is_array($fact) || $previous->string('hash') !== $input->hex
                || ($fact['verdict'] ?? null) !== $inspection->verdict->value
                || ($fact['check_code'] ?? null) !== $inspection->checkCode
                || ($fact['signature_hash'] ?? null) !== $inspection->signature?->hex
                || ($fact['known_defect'] ?? null) !== $inspection->knownDefect
                || ($fact['contract'] ?? null) != $claim->contract->toArray()
                || ($fact['request_ids'] ?? null) !== array_map(static fn ($id): string => $id->value, $requests)) {
                throw new \LogicException('A validation identity cannot be reused for another fact.');
            }

            return new AdapterValidationReceipt($claim->healthId, $claim->epoch, $claim->contract,
                $input, $claim->operationId, $claim->operationId, $requests, $previous->bool('stale'), $inspection->verdict);
        }
        $current = LedgerRow::one("SELECT t.id FROM prc_source_targets t JOIN prc_platforms p ON p.id = t.platform_id
            WHERE t.platform_id = ? AND p.code = ? AND t.capability = ? AND t.retired_at IS NULL
                AND t.endpoint_policy_schema_version = 2 AND t.endpoint_policy->'adapter_contract' = ?::jsonb LIMIT 1",
            [$row->int('platform_id'), $claim->contract->platformCode, $claim->contract->capability,
                json_encode($claim->contract->toArray(), JSON_THROW_ON_ERROR)]);
        $now = HealthRepository::now();
        $stale = $current === null || $owner instanceof TransportBlocked
            || $owner->operationId?->value !== $claim->operationId->value
            || $owner->adapterHealthId?->value !== $claim->healthId->value || $owner->adapterHealthEpoch !== $claim->epoch
            || $row->int('health_epoch') !== $claim->epoch || $row->string('state') === 'held'
            || (! $claim->canary && in_array($row->string('state'), ['validating', 'suspect'], true))
            || ($claim->canary && ($row->nullableString('probe_token') !== $context->lease()->ownerToken->value
                || new \DateTimeImmutable($row->string('probe_until')) <= $now));
        $requests = $context->successfulRequestIds();
        if ($requests === []) {
            throw new \LogicException('Full validation requires real physical response provenance.');
        }
        foreach ($requests as $id) {
            if (LedgerRow::one('SELECT a.request_id FROM prc_request_attempts a JOIN prc_network_demands d ON d.id = a.demand_id
                WHERE a.request_id = ? AND d.operation_id = ? AND a.finished_at IS NOT NULL', [$id->value, $claim->operationId->value]) === null) {
                throw new \LogicException('Validation response belongs to another physical owner.');
            }
        }
        $key = $claim->epochKey();
        $version = $row->int('state_version') + 1;
        $details = ['schema_version' => 1, 'verdict' => $inspection->verdict->value,
            'check_code' => $inspection->checkCode, 'signature_hash' => $inspection->signature?->hex,
            'contract' => $claim->contract->toArray(), 'known_defect' => $inspection->knownDefect,
            'request_ids' => array_map(static fn ($id): string => $id->value, $requests),
            'applied_state_version' => $stale ? null : $version, 'policy_version' => $this->policy->version];
        $inserted = DB::insert("INSERT INTO prc_observations (observation_id, request_id, kind, reason_code, input_hash,
            generation, epochs, epochs_schema_version, stale, details, details_schema_version, occurred_at)
            VALUES (?, ?, 'adapter_validation', ?, decode(?, 'hex'), ?, ?::jsonb, 1, ?, ?::jsonb, 1, ?)
            ON CONFLICT (observation_id) DO NOTHING", [$claim->operationId->value, $requests[array_key_last($requests)]->value,
            $inspection->checkCode, $input->hex, $context->lease()->generation, json_encode([$key => $claim->epoch], JSON_THROW_ON_ERROR),
            $stale, json_encode($details, JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s.uP')]);
        // SQL INSERT ... ON CONFLICT returns true even on a duplicate: application identity is
        // the durable idempotency fence and the stored logical fact must agree.
        $stored = LedgerRow::one("SELECT encode(input_hash, 'hex') AS hash, details, stale FROM prc_observations WHERE observation_id = ?", [$claim->operationId->value]);
        if (! $inserted || $stored === null || $stored->string('hash') !== $input->hex
            || json_decode($stored->string('details'), true, flags: JSON_THROW_ON_ERROR) != $details) {
            throw new \LogicException('A validation identity cannot be reused for another fact.');
        }
        $applied = DB::affectingStatement('INSERT INTO prc_observation_applications (observation_id, projection_key, reducer_version, applied_at)
            VALUES (?, ?, 1, ?) ON CONFLICT DO NOTHING', [$claim->operationId->value, $key, $now->format('Y-m-d H:i:s.uP')]);
        if (! $stale && $applied === 1) {
            $accepted = $inspection->verdict === AdapterVerdict::Accepted;
            $partial = $inspection->verdict === AdapterVerdict::Partial;
            $held = false;
            if ($inspection->verdict === AdapterVerdict::ContractFailure) {
                $evidence = LedgerRow::one("SELECT count(DISTINCT o.input_hash)::bigint AS n
                    FROM prc_observations o JOIN prc_observation_applications a ON a.observation_id = o.observation_id
                    WHERE a.projection_key = ? AND NOT o.stale AND o.kind = 'adapter_validation'
                        AND o.details->>'verdict' = 'contract_failure' AND o.details->>'signature_hash' = ?
                        AND o.occurred_at >= clock_timestamp() - make_interval(secs => ?)
                        AND (o.details->>'applied_state_version')::bigint > COALESCE((
                            SELECT max((r.details->>'applied_state_version')::bigint) FROM prc_observations r
                            JOIN prc_observation_applications ra ON ra.observation_id = r.observation_id
                            WHERE ra.projection_key = ? AND NOT r.stale AND r.kind = 'adapter_validation_reset'), 0)",
                    [$key, $inspection->signature?->hex, $this->policy->windowSeconds, $key]);
                $held = $inspection->knownDefect || ($evidence?->int('n') ?? 0) >= $this->policy->distinctInputs;
            }
            $state = $accepted ? 'healthy' : ($partial ? $row->string('state') : ($held ? 'held' : 'suspect'));
            $revoke = $held || (! $accepted && ! $partial && $row->string('state') === 'healthy');
            // A failed initial canary must remain exclusive on its next attempt. Suspect
            // still uses the one-owner validation path until a full success is accepted.
            // Revoke earlier ordinary owners when entering suspect: their late successes
            // cannot restore normal admission or authorize another physical send.
            $changed = DB::update('UPDATE prc_adapter_health SET state = ?, state_version = ?, health_epoch = health_epoch + ?::int,
                reason_code = ?, last_success_at = CASE WHEN ?::boolean THEN ?::timestamptz ELSE last_success_at END,
                last_failure_at = CASE WHEN ?::boolean THEN last_failure_at ELSE ?::timestamptz END,
                stable_since = CASE WHEN ?::boolean THEN coalesce(stable_since, ?::timestamptz) ELSE NULL END,
                recent_openings = recent_openings + ?::int, probe_token = NULL, probe_until = NULL WHERE id = ?
                AND health_epoch = ? AND (?::boolean = false OR probe_until > clock_timestamp())
                AND EXISTS (SELECT 1 FROM prc_outbound_operations WHERE id = ? AND generation = ? AND owner_token = ?
                    AND state = \'open\' AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp())',
                [$state, $version, $revoke ? 1 : 0, $accepted ? null : $inspection->checkCode,
                    $accepted, $now, $accepted || $partial, $now, $accepted || $partial, $now, $held ? 1 : 0, $claim->healthId->value,
                    $claim->epoch, $claim->canary, $context->lease()->id->value, $context->lease()->generation, $context->lease()->ownerToken->value]);
            if ($changed !== 1) {
                $stale = true;
                DB::update("UPDATE prc_observations SET stale = true, details = jsonb_set(details, '{applied_state_version}', 'null'::jsonb) WHERE observation_id = ?", [$claim->operationId->value]);
            }
            if ($held && ! $stale) {
                $signature = $inspection->signature ?? throw new \LogicException('Held adapter lacks contract evidence.');
                $this->incidents->open(new ContentHash(hash('sha256', $key.':'.$claim->epoch)),
                    'acquisition:platform:'.$claim->contract->platformCode, 'adapter_contract_held',
                    ['adapter_health_id' => $claim->healthId->value, 'observation_id' => $claim->operationId->value,
                        'signature_hash' => $signature->hex, 'health_epoch' => $claim->epoch],
                    self::uuid(), self::uuid(), self::uuid());
            }
        }

        return new AdapterValidationReceipt($claim->healthId, $claim->epoch, $claim->contract,
            $input, $claim->operationId, $claim->operationId, $requests, $stale, $inspection->verdict);
    }

    /** Release only this exact full-validator claim; network abandonment is no semantic reset. */
    public function release(OutboundExecutionContext $context): void
    {
        CallerTransaction::require('Full validation claim release');
        $claim = $context->adapterClaim();
        if ($claim === null || ! $claim->canary) {
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
        $row = LedgerRow::one('SELECT h.health_epoch, h.state FROM prc_adapter_health h
            WHERE h.id = ? FOR SHARE', [$receipt->healthId->value]);
        $fact = LedgerRow::one("SELECT observation_id FROM prc_observations o
            JOIN prc_observation_applications a USING (observation_id)
            WHERE o.observation_id = ? AND a.projection_key = ? AND NOT o.stale
                AND o.kind = 'adapter_validation' AND o.details->>'verdict' = ?
                AND o.details->'contract' = ?::jsonb AND o.input_hash = decode(?, 'hex')
                AND o.epochs->>? = ? AND o.details->'request_ids' = ?::jsonb",
            [$receipt->observationId->value, 'prc_adapter_health:'.$receipt->healthId->value, $receipt->verdict->value,
                json_encode($receipt->contract->toArray(), JSON_THROW_ON_ERROR), $receipt->inputHash->hex,
                'prc_adapter_health:'.$receipt->healthId->value, (string) $receipt->epoch,
                json_encode(array_map(static fn ($id): string => $id->value, $receipt->requestIds), JSON_THROW_ON_ERROR)]);
        if ($receipt->observationId->value !== $receipt->operationId->value || ! in_array($receipt->verdict, [AdapterVerdict::Accepted, AdapterVerdict::Partial], true) || $receipt->stale || $row === null || $row->int('health_epoch') !== $receipt->epoch || $row->string('state') === 'held' || $fact === null
            || LedgerRow::one("SELECT id FROM prc_source_targets WHERE retired_at IS NULL AND capability = ?
                AND endpoint_policy->'adapter_contract' = ?::jsonb LIMIT 1", [$receipt->contract->capability,
                json_encode($receipt->contract->toArray(), JSON_THROW_ON_ERROR)]) === null) {
            throw new OutboundUnavailable(new TransportBlocked(
                NetworkBlockedReason::AdapterHeld));
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
        $id = self::uuid();
        $key = 'prc_adapter_health:'.$row->string('id');
        DB::update("UPDATE prc_adapter_health SET state = 'validating', health_epoch = ?, state_version = ?,
            reason_code = NULL, probe_token = NULL, probe_until = NULL, open_until = NULL, stable_since = NULL WHERE id = ?",
            [$epoch, $version, $row->string('id')]);
        DB::insert("INSERT INTO prc_observations (observation_id, kind, reason_code, input_hash, generation,
            epochs, epochs_schema_version, stale, details, details_schema_version, occurred_at)
            VALUES (?, 'adapter_validation_reset', 'operator_reset', decode(?, 'hex'), 0, ?::jsonb, 1, false, ?::jsonb, 1, clock_timestamp())",
            [$id->value, $contract->fingerprint()->hex, json_encode([$key => $epoch], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 1, 'contract' => $contract->toArray(), 'applied_state_version' => $version,
                    'actor_id' => $actorId, 'reason' => $reason], JSON_THROW_ON_ERROR)]);
        DB::insert('INSERT INTO prc_observation_applications (observation_id, projection_key, reducer_version, applied_at)
            VALUES (?, ?, 1, clock_timestamp())', [$id->value, $key]);
        $controlVersion = $system->int('control_version') + 1;
        DB::update('UPDATE prc_control_scopes SET control_version = ? WHERE id = ?', [$controlVersion, $system->string('id')]);
        $this->transitions->recordControl(self::uuid(), self::uuid(), new ProcessUuid($system->string('id')),
            $controlVersion, $system->string('desired_mode'), $system->string('desired_mode'), 'adapter_validation_reset',
            ActorKind::Operator, $actorId, ['reason' => $reason, 'adapter_health_id' => $row->string('id')]);

        return new NetworkChangeResult(NetworkChangeStatus::Applied, $version);
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }

    /** Called after target/target-health locks, before network budgets; catalog decides requiredness. */
    public function lockForSend(LedgerRow $target, OutboundRequestPlan $plan,
        NetworkOwnerState $owner): LedgerRow|TransportBlocked|null
    {
        CallerTransaction::require('Adapter last-send fence');
        $policy = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
        $binding = is_array($policy) ? ($policy['adapter_contract'] ?? null) : null;
        $denied = static fn (): TransportBlocked => new TransportBlocked(
            NetworkBlockedReason::ConfigurationHold);
        if ($target->int('endpoint_policy_schema_version') === 1) {
            return $binding === null && $plan->adapterClaim === null ? null : $denied();
        }
        if ($target->int('endpoint_policy_schema_version') !== 2 || ! is_array($binding)) {
            return $denied();
        }
        try {
            $contract = AdapterContractRef::fromArray($binding);
        } catch (\InvalidArgumentException) {
            return $denied();
        }
        $claim = $plan->adapterClaim;
        if ($claim === null || ! $claim->contract->equals($contract) || $target->nullableInt('platform_id') === null
            || $owner->platformId !== $target->nullableInt('platform_id')
            || $target->string('capability') !== $contract->capability
            || $owner->operationId?->value !== $claim->operationId->value
            || $owner->adapterHealthId?->value !== $claim->healthId->value || $owner->adapterHealthEpoch !== $claim->epoch) {
            return $denied();
        }
        $row = LedgerRow::one('SELECT h.*, h.probe_until IS NOT NULL AND h.probe_until > clock_timestamp() AS probe_live
            FROM prc_adapter_health h JOIN prc_platforms p ON p.id = h.platform_id
            WHERE h.id = ? AND h.platform_id = ? AND p.code = ? AND h.capability = ?
                AND h.adapter_version = ? AND h.contract_version = ? FOR UPDATE OF h',
            [$claim->healthId->value, $target->int('platform_id'), $contract->platformCode, $contract->capability,
                $contract->adapterVersion, $contract->contractVersion]);
        if ($row === null || $row->int('health_epoch') !== $claim->epoch) {
            return $denied();
        }
        if ($row->string('state') === 'held') {
            return new TransportBlocked(NetworkBlockedReason::AdapterHeld);
        }
        if ($claim->canary) {
            if ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken === null
                || $row->nullableString('probe_token') !== $owner->probeToken || ! $row->bool('probe_live')) {
                return $denied();
            }
        } elseif (in_array($row->string('state'), ['validating', 'suspect'], true) || $row->nullableString('probe_token') !== null) {
            return $denied();
        }

        return $row;
    }
}
