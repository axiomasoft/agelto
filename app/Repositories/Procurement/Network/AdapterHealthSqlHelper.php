<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\AdapterValidation\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidation\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationClaim;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationReceipt;
use App\Dto\Procurement\Network\Control\NetworkOwnerState;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;
use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Proxyma\Caller\CallerSendFacts;
use Illuminate\Support\Facades\DB;

/**
 * SQL-хелперы, provenance-проверки и запись наблюдений для AdapterHealthRepository.
 */
final readonly class AdapterHealthSqlHelper
{
    public static function findActiveContractTarget(?int $platformId, AdapterContractRef $contract): ?LedgerRow
    {
        return LedgerRow::one(
            "SELECT t.id FROM prc_source_targets t JOIN prc_platforms p ON p.id = t.platform_id
             WHERE t.platform_id = ? AND p.code = ? AND t.capability = ? AND t.retired_at IS NULL
                 AND t.endpoint_policy_schema_version = 2 AND t.endpoint_policy->'adapter_contract' = ?::jsonb LIMIT 1",
            [$platformId, $contract->platformCode, $contract->capability, json_encode($contract->toArray(), JSON_THROW_ON_ERROR)],
        );
    }

    public static function activeProbeHolder(string $healthId, int $epoch, ?string $token): ?LedgerRow
    {
        return $token === null ? null : LedgerRow::one(
            "SELECT id FROM prc_outbound_operations
             WHERE adapter_health_id = ? AND adapter_health_epoch = ? AND owner_token = ? AND state = 'open'
                 AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp() LIMIT 1",
            [$healthId, $epoch, $token],
        );
    }

    public static function countAcceptedObservations(string $projectionKey): int
    {
        $accepted = LedgerRow::one(
            "SELECT count(*)::bigint AS n
             FROM prc_observations o JOIN prc_observation_applications a ON a.observation_id = o.observation_id
             WHERE a.projection_key = ? AND NOT o.stale AND o.kind = 'adapter_validation'
                 AND o.details->>'verdict' = 'accepted'
                 AND (o.details->>'applied_state_version')::bigint > COALESCE((
                     SELECT max((r.details->>'applied_state_version')::bigint)
                     FROM prc_observations r JOIN prc_observation_applications ra ON ra.observation_id = r.observation_id
                     WHERE ra.projection_key = ? AND NOT r.stale AND (r.kind = 'adapter_validation_reset'
                         OR (r.kind = 'adapter_validation' AND r.details->>'verdict' IN ('contract_failure', 'handler_fault')))
                 ), 0)",
            [$projectionKey, $projectionKey],
        );

        return $accepted?->int('n') ?? 0;
    }

    public static function countActiveValidationOwners(string $healthId, int $epoch, string $excludeOwnerId): int
    {
        $active = LedgerRow::one(
            "SELECT count(*)::bigint AS n FROM prc_outbound_operations
             WHERE adapter_health_id = ? AND adapter_health_epoch = ? AND id <> ? AND state = 'open'
                 AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$healthId, $epoch, $excludeOwnerId],
        );
        $attempts = LedgerRow::one(
            "SELECT count(*)::bigint AS n FROM prc_process_attempts a
             JOIN prc_process_work w ON w.id = a.work_id AND w.generation = a.generation
             WHERE a.adapter_health_id = ? AND a.adapter_health_epoch = ? AND a.id <> ?
                 AND a.finished_at IS NULL AND w.state = 'running' AND w.lease_until > clock_timestamp()",
            [$healthId, $epoch, $excludeOwnerId],
        );

        return ($active?->int('n') ?? 0) + ($attempts?->int('n') ?? 0);
    }

    public static function attachValidationOwner(
        OutboundExecutionContext|ExecutionContext $context,
        string $healthId,
        int $epoch,
        ProcessUuid $ownerId,
    ): int {
        return $context instanceof ExecutionContext
            ? DB::update(
                'UPDATE prc_process_attempts SET adapter_health_id = ?, adapter_health_epoch = ?
                 WHERE id = ? AND work_id = ? AND generation = ? AND finished_at IS NULL',
                [$healthId, $epoch, $ownerId->value, $context->lease()->workId->value->value, $context->lease()->generation],
            )
            : DB::update(
                "UPDATE prc_outbound_operations SET adapter_health_id = ?, adapter_health_epoch = ?
                 WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'open'
                     AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
                [$healthId, $epoch, $context->lease()->id->value, $context->lease()->generation, $context->lease()->ownerToken->value],
            );
    }

    /**
     * @param  AdapterInspection<mixed>  $inspection
     * @param  list<ProcessUuid>  $requests
     */
    public static function verifyDuplicateObservation(
        LedgerRow $previous,
        AdapterValidationClaim $claim,
        ContentHash $input,
        AdapterInspection $inspection,
        array $requests,
    ): AdapterValidationReceipt {
        $fact = json_decode($previous->string('details'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($fact) || $previous->string('hash') !== $input->hex
            || ($fact['verdict'] ?? null) !== $inspection->verdict->value
            || ($fact['check_code'] ?? null) !== $inspection->checkCode
            || ($fact['signature_hash'] ?? null) !== $inspection->signature?->hex
            || ($fact['known_defect'] ?? null) !== $inspection->knownDefect
            || CanonicalJson::encode($fact['contract'] ?? null) !== CanonicalJson::encode($claim->contract->toArray())
            || ($fact['owner_kind'] ?? null) !== $claim->ownerKind->value
            || ($fact['request_ids'] ?? null) !== array_map(static fn (ProcessUuid $id): string => $id->value, $requests)) {
            throw new \LogicException('A validation identity cannot be reused for another fact.');
        }

        return new AdapterValidationReceipt(
            $claim->healthId, $claim->epoch, $claim->contract,
            $input, $claim->ownerId, $claim->ownerId, $requests, $previous->bool('stale'), $inspection->verdict, $claim->ownerKind,
        );
    }

    public static function isStaleObservation(
        ?LedgerRow $current,
        NetworkOwnerState|TransportBlocked $owner,
        AdapterValidationClaim $claim,
        LedgerRow $row,
        OutboundExecutionContext|ExecutionContext $context,
        \DateTimeImmutable $now,
    ): bool {
        return $current === null || $owner instanceof TransportBlocked
            || ! $owner->ownsValidation($claim)
            || $owner->adapterHealthId?->value !== $claim->healthId->value || $owner->adapterHealthEpoch !== $claim->epoch
            || $row->int('health_epoch') !== $claim->epoch || $row->string('state') === 'held'
            || (! $claim->canary && in_array($row->string('state'), ['validating', 'suspect'], true))
            || ($claim->canary && (! $context instanceof OutboundExecutionContext || $row->nullableString('probe_token') !== $context->lease()->ownerToken->value
                || new \DateTimeImmutable($row->string('probe_until')) <= $now));
    }

    /** @param list<ProcessUuid> $requests */
    public static function assertRequestProvenance(
        AdapterValidationClaim $claim,
        OutboundExecutionContext|ExecutionContext $context,
        array $requests,
    ): void {
        if ($requests === []) {
            throw new \LogicException('Full validation requires real physical response provenance.');
        }
        $ownerColumn = $claim->ownerKind === AdapterValidationOwnerKind::WorkAttempt ? 'attempt_id' : 'operation_id';
        $generationColumn = $claim->ownerKind === AdapterValidationOwnerKind::WorkAttempt ? 'work_generation' : 'operation_generation';
        foreach ($requests as $id) {
            if (LedgerRow::one(
                "SELECT a.request_id FROM prc_request_attempts a JOIN prc_network_demands d ON d.id = a.demand_id
                 WHERE a.request_id = ? AND d.{$ownerColumn} = ? AND d.{$generationColumn} = ? AND a.finished_at IS NOT NULL",
                [$id->value, $claim->ownerId->value, $context->lease()->generation],
            ) === null && ! CallerSendFacts::completed($id->value,
                $claim->ownerKind === AdapterValidationOwnerKind::WorkAttempt ? 'process_attempt' : 'outbound_operation',
                $claim->ownerId->value, $context->lease()->generation)) {
                throw new \LogicException('Validation response belongs to another physical owner.');
            }
        }
    }

    /**
     * @param  AdapterInspection<mixed>  $inspection
     * @param  list<ProcessUuid>  $requests
     */
    public static function recordObservation(
        AdapterValidationClaim $claim,
        OutboundExecutionContext|ExecutionContext $context,
        ContentHash $input,
        AdapterInspection $inspection,
        array $requests,
        bool $stale,
        int $version,
        int $policyVersion,
        \DateTimeImmutable $now,
    ): int {
        $key = $claim->epochKey();
        $details = [
            'schema_version' => 1,
            'verdict' => $inspection->verdict->value,
            'owner_kind' => $claim->ownerKind->value,
            'check_code' => $inspection->checkCode,
            'signature_hash' => $inspection->signature?->hex,
            'contract' => $claim->contract->toArray(),
            'known_defect' => $inspection->knownDefect,
            'request_ids' => array_map(static fn (ProcessUuid $id): string => $id->value, $requests),
            'applied_state_version' => $stale ? null : $version,
            'policy_version' => $policyVersion,
        ];
        $lastKey = array_key_last($requests) ?? throw new \LogicException('At least one physical request is required.');
        // Package provenance уже проверена; legacy FK не может ссылаться на package attempt.
        $legacyRequest = LedgerRow::one('SELECT request_id FROM prc_request_attempts WHERE request_id=?', [$requests[$lastKey]->value]);
        $inserted = DB::insert(
            "INSERT INTO prc_observations (observation_id, request_id, kind, reason_code, input_hash,
                generation, epochs, epochs_schema_version, stale, details, details_schema_version, occurred_at)
             VALUES (?, ?, 'adapter_validation', ?, decode(?, 'hex'), ?, ?::jsonb, 1, ?, ?::jsonb, 1, ?)
             ON CONFLICT (observation_id) DO NOTHING",
            [$claim->ownerId->value, $legacyRequest?->string('request_id'),
                $inspection->checkCode, $input->hex, $context->lease()->generation, json_encode([$key => $claim->epoch], JSON_THROW_ON_ERROR),
                $stale, json_encode($details, JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s.uP')],
        );
        $stored = LedgerRow::one("SELECT encode(input_hash, 'hex') AS hash, details, stale FROM prc_observations WHERE observation_id = ?", [$claim->ownerId->value]);
        if (! $inserted || $stored === null || $stored->string('hash') !== $input->hex
            || CanonicalJson::encode(json_decode($stored->string('details'), true, flags: JSON_THROW_ON_ERROR)) !== CanonicalJson::encode($details)) {
            throw new \LogicException('A validation identity cannot be reused for another fact.');
        }

        return DB::affectingStatement(
            'INSERT INTO prc_observation_applications (observation_id, projection_key, reducer_version, applied_at)
             VALUES (?, ?, 1, ?) ON CONFLICT DO NOTHING',
            [$claim->ownerId->value, $key, $now->format('Y-m-d H:i:s.uP')],
        );
    }

    public static function countContractFailureInputs(string $key, ?string $signatureHex, int $windowSeconds): int
    {
        $evidence = LedgerRow::one(
            "SELECT count(DISTINCT o.input_hash)::bigint AS n
             FROM prc_observations o JOIN prc_observation_applications a ON a.observation_id = o.observation_id
             WHERE a.projection_key = ? AND NOT o.stale AND o.kind = 'adapter_validation'
                 AND o.details->>'verdict' = 'contract_failure' AND o.details->>'signature_hash' = ?
                 AND o.occurred_at >= clock_timestamp() - make_interval(secs => ?)
                 AND (o.details->>'applied_state_version')::bigint > COALESCE((
                     SELECT max((r.details->>'applied_state_version')::bigint) FROM prc_observations r
                     JOIN prc_observation_applications ra ON ra.observation_id = r.observation_id
                     WHERE ra.projection_key = ? AND NOT r.stale AND r.kind = 'adapter_validation_reset'), 0)",
            [$key, $signatureHex, $windowSeconds, $key],
        );

        return $evidence?->int('n') ?? 0;
    }

    public static function applyObservedHealthState(
        OutboundExecutionContext|ExecutionContext $context,
        AdapterValidationClaim $claim,
        string $state,
        int $version,
        bool $revoke,
        ?string $reasonCode,
        bool $accepted,
        bool $partial,
        bool $held,
        \DateTimeImmutable $now,
    ): int {
        $ownerSql = $context instanceof ExecutionContext
            ? "SELECT 1 FROM prc_process_work WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > clock_timestamp()"
            : "SELECT 1 FROM prc_outbound_operations o WHERE o.id = ? AND o.generation = ? AND o.owner_token = ? AND o.state = 'open' AND o.lease_until > clock_timestamp() AND o.deadline_at > clock_timestamp()
                AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id AND w.generation=a.generation
                    WHERE a.id=o.parent_attempt_id AND a.finished_at IS NULL AND w.state='running' AND w.lease_until > clock_timestamp()))";
        $ownerParameters = $context instanceof ExecutionContext
            ? [$context->lease()->workId->value->value, $context->lease()->generation, $context->lease()->token->value]
            : [$context->lease()->id->value, $context->lease()->generation, $context->lease()->ownerToken->value];

        $timestamp = $now->format('Y-m-d H:i:s.uP');

        return DB::update(
            'UPDATE prc_adapter_health SET state = ?, state_version = ?, health_epoch = health_epoch + ?::int,
                reason_code = ?, last_success_at = CASE WHEN ?::boolean THEN ?::timestamptz ELSE last_success_at END,
                last_failure_at = CASE WHEN ?::boolean THEN last_failure_at ELSE ?::timestamptz END,
                stable_since = CASE WHEN ?::boolean THEN coalesce(stable_since, ?::timestamptz) ELSE NULL END,
                recent_openings = recent_openings + ?::int, probe_token = NULL, probe_until = NULL WHERE id = ?
                AND health_epoch = ? AND (?::boolean = false OR probe_until > clock_timestamp())
                AND EXISTS ('.$ownerSql.')',
            [$state, $version, $revoke ? 1 : 0, $reasonCode,
                $accepted, $timestamp, $accepted || $partial, $timestamp, $accepted || $partial, $timestamp, $held ? 1 : 0, $claim->healthId->value,
                $claim->epoch, $claim->canary, ...$ownerParameters],
        );
    }

    public static function verifyReceiptFact(AdapterValidationReceipt $receipt): ?LedgerRow
    {
        return LedgerRow::one(
            "SELECT observation_id FROM prc_observations o
             JOIN prc_observation_applications a USING (observation_id)
             WHERE o.observation_id = ? AND a.projection_key = ? AND NOT o.stale
                 AND o.kind = 'adapter_validation' AND o.details->>'verdict' = ?
                 AND o.details->'contract' = ?::jsonb AND o.input_hash = decode(?, 'hex')
                 AND o.epochs->>? = ? AND o.details->'request_ids' = ?::jsonb AND o.details->>'owner_kind' = ?",
            [$receipt->observationId->value, 'prc_adapter_health:'.$receipt->healthId->value, $receipt->verdict->value,
                json_encode($receipt->contract->toArray(), JSON_THROW_ON_ERROR), $receipt->inputHash->hex,
                'prc_adapter_health:'.$receipt->healthId->value, (string) $receipt->epoch,
                json_encode(array_map(static fn (ProcessUuid $id): string => $id->value, $receipt->requestIds), JSON_THROW_ON_ERROR), $receipt->ownerKind->value],
        );
    }

    public static function recordResetObservation(
        AdapterContractRef $contract,
        string $healthId,
        int $epoch,
        int $version,
        int $actorId,
        string $reason,
    ): void {
        $id = ProcessUuid::random();
        $key = 'prc_adapter_health:'.$healthId;
        DB::update(
            "UPDATE prc_adapter_health SET state = 'validating', health_epoch = ?, state_version = ?,
                reason_code = NULL, probe_token = NULL, probe_until = NULL, open_until = NULL, stable_since = NULL WHERE id = ?",
            [$epoch, $version, $healthId],
        );
        DB::insert(
            "INSERT INTO prc_observations (observation_id, kind, reason_code, input_hash, generation,
                epochs, epochs_schema_version, stale, details, details_schema_version, occurred_at)
             VALUES (?, 'adapter_validation_reset', 'operator_reset', decode(?, 'hex'), 0, ?::jsonb, 1, false, ?::jsonb, 1, clock_timestamp())",
            [$id->value, $contract->fingerprint()->hex, json_encode([$key => $epoch], JSON_THROW_ON_ERROR),
                json_encode(['schema_version' => 1, 'contract' => $contract->toArray(), 'applied_state_version' => $version,
                    'actor_id' => $actorId, 'reason' => $reason], JSON_THROW_ON_ERROR)],
        );
        DB::insert(
            'INSERT INTO prc_observation_applications (observation_id, projection_key, reducer_version, applied_at)
             VALUES (?, ?, 1, clock_timestamp())',
            [$id->value, $key],
        );
    }

    public static function lockAdapterRowForSend(
        LedgerRow $target,
        OutboundRequestPlan $plan,
        NetworkOwnerState $owner,
    ): LedgerRow|TransportBlocked|null {
        $policy = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
        $binding = is_array($policy) ? ($policy['adapter_contract'] ?? null) : null;
        $denied = static fn (): TransportBlocked => new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
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
            || ! $owner->ownsValidation($claim)
            || $owner->adapterHealthId?->value !== $claim->healthId->value || $owner->adapterHealthEpoch !== $claim->epoch) {
            return $denied();
        }
        $row = LedgerRow::one(
            'SELECT h.*, h.probe_until IS NOT NULL AND h.probe_until > clock_timestamp() AS probe_live
             FROM prc_adapter_health h JOIN prc_platforms p ON p.id = h.platform_id
             WHERE h.id = ? AND h.platform_id = ? AND p.code = ? AND h.capability = ?
                 AND h.adapter_version = ? AND h.contract_version = ? FOR UPDATE OF h',
            [$claim->healthId->value, $target->int('platform_id'), $contract->platformCode, $contract->capability,
                $contract->adapterVersion, $contract->contractVersion],
        );
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
