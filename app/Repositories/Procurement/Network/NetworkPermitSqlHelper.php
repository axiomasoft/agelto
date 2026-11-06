<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkOwnerState;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\PermitEvidence;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\OutboundPolicy;
use Illuminate\Support\Facades\DB;

/**
 * SQL-хелперы, GCRA/FIFO расчёты и reaper-запросы для NetworkPermitRepository.
 */
final readonly class NetworkPermitSqlHelper
{
    public static function demand(NetworkDemandTicket $ticket, ExecutionLease|OutboundOperationHandle $owner): ?LedgerRow
    {
        $row = LedgerRow::one('SELECT * FROM prc_network_demands WHERE id = ? FOR UPDATE', [$ticket->id->value]);

        return $row !== null && self::matches($row, $owner, $ticket->plan, $ticket->route) ? $row : null;
    }

    public static function ownerLive(ExecutionLease|OutboundOperationHandle $owner): bool
    {
        return ($owner instanceof ExecutionLease
            ? LedgerRow::one("SELECT 1 AS live FROM prc_process_work WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > clock_timestamp()", [$owner->workId->value->value, $owner->generation, $owner->token->value])
            : LedgerRow::one("SELECT 1 AS live FROM prc_outbound_operations o WHERE o.id = ? AND o.generation = ? AND o.owner_token = ? AND o.state = 'open' AND o.lease_until > clock_timestamp() AND o.deadline_at > clock_timestamp()
                AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id AND w.generation=a.generation
                    WHERE a.id=o.parent_attempt_id AND a.finished_at IS NULL AND w.state='running' AND w.lease_until > clock_timestamp()))", [$owner->id->value, $owner->generation, $owner->ownerToken->value])) !== null;
    }

    public static function matches(LedgerRow $row, ExecutionLease|OutboundOperationHandle $owner, OutboundRequestPlan $plan, NetworkRouteSelection $route): bool
    {
        return $row->string('physical_request_id') === $plan->physicalRequestId->value && $row->string('target_id') === $plan->targetId->value
            && $row->string('route_id') === $route->routeId->value && $row->string('traffic_class') === $plan->trafficClass->value
            && ($owner instanceof ExecutionLease
                ? $row->nullableString('work_id') === $owner->workId->value->value && $row->nullableString('attempt_id') === $owner->attemptId->value && $row->nullableInt('work_generation') === $owner->generation
                : $row->nullableString('operation_id') === $owner->id->value && $row->nullableInt('operation_generation') === $owner->generation);
    }

    public static function cancelOwned(NetworkDemandTicket $ticket, ExecutionLease|OutboundOperationHandle $owner): void
    {
        if (self::demand($ticket, $owner) !== null) {
            self::cancel($ticket);
        }
    }

    public static function cancel(NetworkDemandTicket $ticket): void
    {
        DB::update("UPDATE prc_network_demands SET state = 'cancelled' WHERE id = ? AND state = 'queued'", [$ticket->id->value]);
        DB::update("UPDATE prc_network_permits SET state = 'released' WHERE demand_id = ? AND state = 'reserved'", [$ticket->id->value]);
    }

    public static function expireQueuedForOwner(ExecutionLease|OutboundOperationHandle $owner): ?LedgerRow
    {
        if ($owner instanceof ExecutionLease) {
            DB::update("UPDATE prc_network_demands SET state = 'expired' WHERE attempt_id = ? AND state = 'queued' AND expires_at <= clock_timestamp()", [$owner->attemptId->value]);

            return LedgerRow::one("SELECT id, expires_at::text AS expires_at FROM prc_network_demands WHERE attempt_id = ? AND state = 'queued'", [$owner->attemptId->value]);
        }
        DB::update("UPDATE prc_network_demands SET state = 'expired' WHERE operation_id = ? AND state = 'queued' AND expires_at <= clock_timestamp()", [$owner->id->value]);

        return LedgerRow::one("SELECT id, expires_at::text AS expires_at FROM prc_network_demands WHERE operation_id = ? AND state = 'queued'", [$owner->id->value]);
    }

    public static function insertDemand(
        string $id,
        OutboundRequestPlan $plan,
        NetworkRouteSelection $route,
        ExecutionLease|OutboundOperationHandle $owner,
        NetworkOwnerState $state,
        string $originBudgetId,
        int $now,
        int $expires,
        \DateTimeImmutable $deadline,
    ): LedgerRow {
        return LedgerRow::one(
            "INSERT INTO prc_network_demands (id, physical_request_id, work_id, attempt_id, work_generation, operation_id, operation_generation,
             origin_budget_id, route_id, target_id, traffic_class, purpose, registered_at, renewed_at, expires_at, operation_deadline, state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued') RETURNING ticket_seq",
            [$id, $plan->physicalRequestId->value, $owner instanceof ExecutionLease ? $owner->workId->value->value : null,
                $owner instanceof ExecutionLease ? $owner->attemptId->value : null, $owner instanceof ExecutionLease ? $owner->generation : null,
                $owner instanceof OutboundOperationHandle ? $owner->id->value : null, $owner instanceof OutboundOperationHandle ? $owner->generation : null,
                $originBudgetId, $route->routeId->value, $plan->targetId->value, $plan->trafficClass->value, $state->purpose->value,
                NetworkAuthorityRepository::date($now), NetworkAuthorityRepository::date($now), NetworkAuthorityRepository::date($expires), $deadline->format('Y-m-d H:i:s.uP')],
        ) ?? throw new \LogicException('Demand registration returned no ticket.');
    }

    public static function insertReservedPermit(
        string $permitId,
        NetworkDemandTicket $ticket,
        LockedNetwork $network,
        int $slot,
        int $expires,
        int $now,
    ): ScheduledNetworkPermit {
        foreach ($network->budgets as $budget) {
            DB::update("UPDATE prc_network_budgets SET tat = greatest(tat, ?::timestamptz) + emission_interval_us * interval '1 microsecond' WHERE id = ?", [NetworkAuthorityRepository::date($slot), $budget->string('id')]);
        }
        DB::insert(
            "INSERT INTO prc_network_permits (id, demand_id, slot_at, expires_at, state, policy_versions, policy_schema_version, health_epochs, health_epochs_schema_version, created_at)
             VALUES (?, ?, ?, ?, 'reserved', ?::jsonb, 1, ?::jsonb, 1, ?)",
            [$permitId, $ticket->id->value, NetworkAuthorityRepository::date($slot), NetworkAuthorityRepository::date($expires), json_encode($network->evidence->policies, JSON_THROW_ON_ERROR), json_encode($network->evidence->epochs, JSON_THROW_ON_ERROR), NetworkAuthorityRepository::date($now)],
        );
        foreach ($network->budgets as $budget) {
            DB::insert('INSERT INTO prc_network_permit_scopes (permit_id, budget_id) VALUES (?, ?)', [$permitId, $budget->string('id')]);
        }
        DB::update("UPDATE prc_network_demands SET state = 'granted' WHERE id = ?", [$ticket->id->value]);

        return new ScheduledNetworkPermit(new ProcessUuid($permitId), $ticket, NetworkAuthorityRepository::instant($slot), NetworkAuthorityRepository::instant($expires));
    }

    public static function hasValidPermitEvidence(string $permitId, LedgerRow $row, LockedNetwork $network): bool
    {
        $scopeIds = array_map(static fn (LedgerRow $scope): string => $scope->string('budget_id'), LedgerRow::all('SELECT budget_id FROM prc_network_permit_scopes WHERE permit_id = ? ORDER BY budget_id', [$permitId]));
        $expectedIds = array_map(static fn (LedgerRow $budget): string => $budget->string('id'), array_values($network->budgets));
        sort($expectedIds, SORT_STRING);

        return $scopeIds === $expectedIds
            && PermitEvidence::decode($row->string('policy_versions'), $row->int('policy_schema_version')) === $network->evidence->policies
            && PermitEvidence::decode($row->string('health_epochs'), $row->int('health_epochs_schema_version')) === $network->evidence->epochs;
    }

    public static function authorizeSendCas(
        ScheduledNetworkPermit $permit,
        ExecutionLease|OutboundOperationHandle $owner,
        int $reserveSeconds,
    ): ?LedgerRow {
        $ticket = $permit->ticket;
        $liveOwner = $owner instanceof ExecutionLease
            ? "SELECT 1 FROM prc_process_work WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > n.t"
            : "SELECT 1 FROM prc_outbound_operations o WHERE o.id = ? AND o.generation = ? AND o.owner_token = ? AND o.state = 'open' AND o.lease_until > n.t
                AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id AND w.generation=a.generation
                    WHERE a.id=o.parent_attempt_id AND a.finished_at IS NULL AND w.state='running' AND w.lease_until > n.t))
                AND deadline_at >= n.t + make_interval(secs => ".($ticket->plan->profile->totalSeconds + 15).')';
        $ownerId = $owner instanceof ExecutionLease ? $owner->workId->value->value : $owner->id->value;
        $token = $owner instanceof ExecutionLease ? $owner->token->value : $owner->ownerToken->value;

        return LedgerRow::one("WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS t)
            UPDATE prc_network_permits SET state = 'sent', authorized_at = n.t,
                expires_at = greatest(expires_at, n.t + make_interval(secs => ?)) FROM instant n
            WHERE id = ? AND state = 'reserved' AND expires_at > n.t AND EXISTS ({$liveOwner})
                AND EXISTS (SELECT 1 FROM prc_network_demands d WHERE d.id = prc_network_permits.demand_id
                    AND d.operation_deadline >= n.t + make_interval(secs => ".($ticket->plan->profile->totalSeconds + 15).'))
            RETURNING authorized_at::text AS authorized_at, expires_at::text AS expires_at',
            [$ticket->plan->profile->totalSeconds + $reserveSeconds, $permit->id->value, $ownerId, $owner->generation, $token]);
    }

    public static function recordAuthorizedAttempt(NetworkDemandTicket $ticket, LockedNetwork $network, int $now): void
    {
        foreach ($network->budgets as $budget) {
            DB::update("UPDATE prc_network_budgets SET send_tat = greatest(send_tat, ?::timestamptz) + emission_interval_us * interval '1 microsecond' WHERE id = ?", [NetworkAuthorityRepository::date($now), $budget->string('id')]);
        }
        DB::insert(
            "INSERT INTO prc_request_attempts (request_id, demand_id, request_seq, target_id, route_id, started_at, sent_at, phase, epochs, epochs_schema_version, artifacts, artifacts_schema_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'authorized', ?::jsonb, 1, ?::jsonb, 1)",
            [$ticket->plan->physicalRequestId->value, $ticket->id->value, $ticket->ticketSeq,
                $ticket->plan->targetId->value, $ticket->route->routeId->value, NetworkAuthorityRepository::date($now), NetworkAuthorityRepository::date($now), json_encode($network->evidence->epochs, JSON_THROW_ON_ERROR), json_encode($network->healthAuthorization->toArray(), JSON_THROW_ON_ERROR)],
        );
    }

    /** @return list<LedgerRow> */
    public static function reapableOrigins(int $limit): array
    {
        $deadDemand = self::deadDemandSql('d');

        return LedgerRow::all("SELECT b.id FROM prc_network_budgets b WHERE b.scope_key LIKE 'origin:%' AND (
            EXISTS (SELECT 1 FROM prc_network_demands d WHERE d.origin_budget_id = b.id AND d.state = 'queued' AND (
                d.expires_at <= clock_timestamp() OR {$deadDemand}))
            OR EXISTS (SELECT 1 FROM prc_network_demands d JOIN prc_network_permits p ON p.demand_id = d.id
                WHERE d.origin_budget_id = b.id AND (
                    (p.state IN ('reserved','sent') AND p.expires_at <= clock_timestamp())
                    OR (p.state = 'reserved' AND ({$deadDemand}))
                )))
            ORDER BY b.scope_key COLLATE \"C\" LIMIT ?", [max(1, min(64, $limit))]);
    }

    public static function pruneLocked(string $originId): int
    {
        $deadQueued = self::deadDemandSql('q');
        $deadDemand = self::deadDemandSql('d');
        $expired = DB::update("UPDATE prc_network_demands d SET state = 'expired' WHERE d.id IN (
            SELECT q.id FROM prc_network_demands q WHERE q.origin_budget_id = ? AND q.state = 'queued' AND (
                q.expires_at <= clock_timestamp() OR {$deadQueued})
            ORDER BY q.ticket_seq LIMIT 64)", [$originId]);

        return $expired + DB::update("UPDATE prc_network_permits p SET state = 'expired' WHERE p.id IN (
            SELECT p2.id FROM prc_network_permits p2 JOIN prc_network_demands d ON d.id = p2.demand_id
            WHERE d.origin_budget_id = ? AND (
                (p2.state IN ('reserved','sent') AND p2.expires_at <= clock_timestamp())
                OR (p2.state = 'reserved' AND ({$deadDemand}))
            )
            ORDER BY p2.expires_at LIMIT 64)", [$originId]);
    }

    public static function deadDemandSql(string $alias): string
    {
        return "{$alias}.operation_deadline <= clock_timestamp()
            OR ({$alias}.operation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_outbound_operations o WHERE o.id = {$alias}.operation_id AND o.generation = {$alias}.operation_generation AND o.state = 'open' AND o.lease_until > clock_timestamp() AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts pa JOIN prc_process_work pw ON pw.id=pa.work_id AND pw.generation=pa.generation WHERE pa.id=o.parent_attempt_id AND pa.finished_at IS NULL AND pw.state='running' AND pw.lease_until > clock_timestamp()))))
            OR ({$alias}.work_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_process_work w WHERE w.id = {$alias}.work_id AND w.generation = {$alias}.work_generation AND w.state = 'running' AND w.lease_until > clock_timestamp()))";
    }

    public static function fits(ExecutionContext|OutboundExecutionContext $context, NetworkOwnerState $state, int $now, int $slot, OutboundRequestPlan $plan, ?\DateTimeImmutable $demandDeadline = null): bool
    {
        $needed = max(0, $slot - $now) + $plan->profile->totalSeconds * 1_000_000;

        return $context->canStart(new Duration((int) ceil($needed / 1000)))
            && ($state->deadlineAt === null || NetworkAuthorityRepository::micros($state->deadlineAt) >= $now + $needed + 15_000_000)
            && ($demandDeadline === null || NetworkAuthorityRepository::micros($demandDeadline) >= $now + $needed + 15_000_000);
    }

    public static function waitOrDefer(
        NetworkDemandTicket $ticket,
        LedgerRow $demand,
        ExecutionContext|OutboundExecutionContext $context,
        NetworkOwnerState $state,
        int $now,
        int $retry,
        NetworkDeferralReason $reason,
        OutboundPolicy $policy,
    ): NetworkPermitWait|TransportDeferred {
        $registered = NetworkAuthorityRepository::micros(new \DateTimeImmutable($demand->string('registered_at')));
        if ($now >= $registered + $policy->maxInlineWaitSeconds * 1_000_000 || ! self::fits($context, $state, $now, $retry, $ticket->plan, new \DateTimeImmutable($demand->string('operation_deadline')))) {
            self::cancel($ticket);

            return self::deferred($reason, max($retry, $now + 1_000_000));
        }
        DB::update('UPDATE prc_network_demands SET renewed_at = ?, expires_at = least(operation_deadline, ?::timestamptz) WHERE id = ? AND expires_at > clock_timestamp()',
            [NetworkAuthorityRepository::date($now), NetworkAuthorityRepository::date($now + $policy->ticketTtlSeconds * 1_000_000), $ticket->id->value]);

        return new NetworkPermitWait(new RetryAt(NetworkAuthorityRepository::instant(min($retry, $now + $policy->ticketRenewSeconds * 1_000_000))), $reason);
    }

    public static function slot(LockedNetwork $network, string $column, int $now): int
    {
        if (! in_array($column, ['tat', 'send_tat'], true)) {
            throw new \LogicException('Invalid GCRA column.');
        }
        $ids = array_map(static fn (LedgerRow $b): string => $b->string('id'), array_values($network->budgets));
        $row = LedgerRow::one("SELECT ceil(extract(epoch FROM max(greatest(?::timestamptz, not_before, {$column} - emission_interval_us * (burst - 1) * interval '1 microsecond'))) * 1000000)::bigint AS slot
            FROM prc_network_budgets WHERE id IN (".LedgerRow::placeholders(count($ids)).')', [NetworkAuthorityRepository::date($now), ...$ids]);

        return $row?->int('slot') ?? throw new \LogicException('No GCRA authorities.');
    }

    public static function capacityAt(LockedNetwork $network): ?int
    {
        $retry = null;
        foreach ($network->budgets as $budget) {
            $row = LedgerRow::one("SELECT count(*) AS n, ceil(extract(epoch FROM min(p.expires_at)) * 1000000)::bigint AS next
                FROM prc_network_permit_scopes s JOIN prc_network_permits p ON p.id = s.permit_id
                WHERE s.budget_id = ? AND p.state IN ('reserved','sent') AND p.expires_at > clock_timestamp()", [$budget->string('id')]);
            if ($row !== null && $row->int('n') >= $budget->int('max_inflight')) {
                $retry = max($retry ?? 0, $row->int('next'));
            }
        }

        return $retry;
    }

    public static function quotaRetry(LockedNetwork $network, int $statusCapacityPercent, ?string $excludePermit = null): ?int
    {
        $interval = $network->origin->int('emission_interval_us');
        $rate = intdiv(59_999_999, $interval) + 1;
        $cap = intdiv($rate * 60 * $statusCapacityPercent, 100);
        if ($cap === 0) {
            return NetworkAuthorityRepository::nowMicros() + 3_600_000_000;
        }
        $rows = LedgerRow::all("SELECT ceil(extract(epoch FROM CASE WHEN p.authorized_at IS NOT NULL THEN p.authorized_at + interval '1 hour' ELSE p.expires_at END) * 1000000)::bigint AS release_at
            FROM prc_network_demands d JOIN prc_network_permits p ON p.demand_id = d.id
            WHERE d.origin_budget_id = ? AND d.traffic_class = 'status_refresh' AND (?::uuid IS NULL OR p.id <> ?::uuid)
              AND (p.authorized_at > clock_timestamp() - interval '1 hour' OR (p.state = 'reserved' AND p.expires_at > clock_timestamp()))
            ORDER BY release_at", [$network->origin->string('id'), $excludePermit, $excludePermit]);

        return count($rows) < $cap ? null : $rows[count($rows) - $cap]->int('release_at');
    }

    public static function permit(LedgerRow $row, NetworkDemandTicket $ticket): ScheduledNetworkPermit
    {
        return new ScheduledNetworkPermit(new ProcessUuid($row->string('id')), $ticket, new \DateTimeImmutable($row->string('slot_at')), new \DateTimeImmutable($row->string('expires_at')));
    }

    public static function deferred(NetworkDeferralReason $reason, int $at): TransportDeferred
    {
        return new TransportDeferred($reason, new RetryAt(NetworkAuthorityRepository::instant($at)));
    }
}
