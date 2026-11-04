<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkOwnerState;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\NetworkSendClaim;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\PermitEvidence;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PostgreSQL authority for bounded FIFO/GCRA and the distinct actual-send fence.
 * Rate debt is never refunded. No transport, sleep, health reduction or business publication here.
 * All identities are allocated before ProcessTransaction retry; DB time is read after row locks.
 */
final readonly class NetworkPermitRepository
{
    public function __construct(private NetworkOwnerFence $owners, private NetworkAuthorityRepository $network,
        private ProcessTransaction $transaction, private OutboundPolicy $policy, private HealthRepository $health) {}

    public function register(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, NetworkRouteSelection $route, ?ProviderKey $provider = null): NetworkDemandTicket|TransportDeferred|TransportBlocked
    {
        $id = (string) Str::uuid();
        $owner = $context->lease();
        $snapshot = $this->owners->snapshot($owner, $provider);

        return $this->transaction->run(function () use ($id, $plan, $context, $route, $owner, $snapshot, $provider) {
            $state = $this->owners->lock($owner, $snapshot, $provider);
            if ($state instanceof TransportBlocked) {
                return $state;
            }
            $network = $this->network->lock($plan, $route, $state);
            if ($network instanceof TransportBlocked) {
                return $network;
            }
            if (! $this->ownerLive($owner)) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $now = self::now();
            $this->pruneLocked($network->origin->string('id'));
            $existing = LedgerRow::one('SELECT * FROM prc_network_demands WHERE physical_request_id = ? FOR UPDATE', [$plan->physicalRequestId->value]);
            if ($existing !== null) {
                if (! $this->matches($existing, $owner, $plan, $route)) {
                    return new TransportBlocked(NetworkBlockedReason::OwnerLost);
                }
                if (! in_array($existing->string('state'), ['queued', 'granted'], true)) {
                    return $this->deferred(NetworkDeferralReason::CapacityWait, $now + 1_000_000);
                }

                return new NetworkDemandTicket(new ProcessUuid($existing->string('id')), $existing->int('ticket_seq'), $plan, $route);
            }
            if (! $this->fits($context, $state, $now, $now, $plan)) {
                return $this->deferred(NetworkDeferralReason::SliceExhausted, $now + 1_000_000);
            }
            $queue = LedgerRow::one("SELECT count(*) AS n, min(expires_at)::text AS next FROM prc_network_demands WHERE origin_budget_id = ? AND state = 'queued'", [$network->origin->string('id')]);
            if ($queue !== null && $queue->int('n') >= $this->policy->maxPendingDemandsPerOrigin) {
                return new TransportDeferred(NetworkDeferralReason::CapacityWait, new RetryAt(new \DateTimeImmutable($queue->string('next'))));
            }
            // Expired own ticket in a different origin must not block the owner's next identity.
            if ($owner instanceof ExecutionLease) {
                DB::update("UPDATE prc_network_demands SET state = 'expired' WHERE attempt_id = ? AND state = 'queued' AND expires_at <= clock_timestamp()", [$owner->attemptId->value]);
            } else {
                DB::update("UPDATE prc_network_demands SET state = 'expired' WHERE operation_id = ? AND state = 'queued' AND expires_at <= clock_timestamp()", [$owner->id->value]);
            }
            // One pending physical request per owner, including across different origins.
            $pending = $owner instanceof ExecutionLease
                ? LedgerRow::one("SELECT id, expires_at::text AS expires_at FROM prc_network_demands WHERE attempt_id = ? AND state = 'queued'", [$owner->attemptId->value])
                : LedgerRow::one("SELECT id, expires_at::text AS expires_at FROM prc_network_demands WHERE operation_id = ? AND state = 'queued'", [$owner->id->value]);
            if ($pending !== null) {
                return new TransportDeferred(NetworkDeferralReason::CapacityWait, new RetryAt(new \DateTimeImmutable($pending->string('expires_at'))));
            }
            $deadline = $state->deadlineAt ?? self::instant($now + $context->remainingSlice()->milliseconds * 1000);
            $expires = min(self::micros($deadline), $now + $this->policy->ticketTtlSeconds * 1_000_000);
            $row = LedgerRow::one(
                "INSERT INTO prc_network_demands (id, physical_request_id, work_id, attempt_id, work_generation, operation_id, operation_generation,
                 origin_budget_id, route_id, target_id, traffic_class, purpose, registered_at, renewed_at, expires_at, operation_deadline, state)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued') RETURNING ticket_seq",
                [$id, $plan->physicalRequestId->value, $owner instanceof ExecutionLease ? $owner->workId->value->value : null,
                    $owner instanceof ExecutionLease ? $owner->attemptId->value : null, $owner instanceof ExecutionLease ? $owner->generation : null,
                    $owner instanceof OutboundOperationHandle ? $owner->id->value : null, $owner instanceof OutboundOperationHandle ? $owner->generation : null,
                    $network->origin->string('id'), $route->routeId->value, $plan->targetId->value, $plan->trafficClass->value, $state->purpose->value,
                    self::date($now), self::date($now), self::date($expires), $deadline->format('Y-m-d H:i:s.uP')],
            ) ?? throw new \LogicException('Demand registration returned no ticket.');

            return new NetworkDemandTicket(new ProcessUuid($id), $row->int('ticket_seq'), $plan, $route);
        });
    }

    public function reserve(NetworkDemandTicket $ticket, ExecutionContext|OutboundExecutionContext $context, ?ProviderKey $provider = null): ScheduledNetworkPermit|NetworkPermitWait|TransportDeferred|TransportBlocked
    {
        $permitId = (string) Str::uuid();
        $owner = $context->lease();
        $snapshot = $this->owners->snapshot($owner, $provider);

        return $this->transaction->run(function () use ($ticket, $context, $owner, $snapshot, $permitId, $provider) {
            $state = $this->owners->lock($owner, $snapshot, $provider);
            if ($state instanceof TransportBlocked) {
                $this->cancelOwned($ticket, $owner);

                return $state;
            }
            $network = $this->network->lock($ticket->plan, $ticket->route, $state);
            if ($network instanceof TransportBlocked) {
                $this->cancelOwned($ticket, $owner);

                return $network;
            }
            if (! $this->ownerLive($owner)) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $now = self::now();
            $this->pruneLocked($network->origin->string('id'));
            $demand = $this->demand($ticket, $owner);
            if ($demand === null) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $existing = LedgerRow::one('SELECT * FROM prc_network_permits WHERE demand_id = ? FOR UPDATE', [$ticket->id->value]);
            if ($existing !== null && $existing->string('state') === 'reserved') {
                return self::permit($existing, $ticket);
            }
            if ($demand->string('state') !== 'queued') {
                return $this->deferred(NetworkDeferralReason::CapacityWait, $now + 1_000_000);
            }
            $originId = $network->origin->string('id');
            $head = LedgerRow::one("SELECT id FROM prc_network_demands WHERE origin_budget_id = ? AND state = 'queued' ORDER BY ticket_seq LIMIT 1", [$originId]);
            $capacityAt = $this->capacityAt($network);
            if ($head?->string('id') !== $ticket->id->value || $capacityAt !== null) {
                return $this->waitOrDefer($ticket, $demand, $context, $state, $now, $capacityAt ?? $now + $this->policy->ticketTtlSeconds * 1_000_000, NetworkDeferralReason::CapacityWait);
            }
            if ($ticket->plan->trafficClass === TrafficClass::StatusRefresh && ($quota = $this->quotaRetry($network)) !== null) {
                $this->cancel($ticket);

                return $this->deferred(NetworkDeferralReason::StatusRefreshBudget, $quota);
            }
            $slot = $this->slot($network, 'tat', $now);
            if ($slot - $now > $this->policy->maxInlineWaitSeconds * 1_000_000 || ! $this->fits($context, $state, $now, $slot, $ticket->plan, new \DateTimeImmutable($demand->string('operation_deadline')))) {
                $this->cancel($ticket);

                return $this->deferred($this->fits($context, $state, $now, $now, $ticket->plan, new \DateTimeImmutable($demand->string('operation_deadline'))) ? NetworkDeferralReason::RateLimit : NetworkDeferralReason::SliceExhausted, $slot);
            }
            foreach ($network->budgets as $budget) {
                DB::update("UPDATE prc_network_budgets SET tat = greatest(tat, ?::timestamptz) + emission_interval_us * interval '1 microsecond' WHERE id = ?", [self::date($slot), $budget->string('id')]);
            }
            $expires = $slot + ($ticket->plan->profile->totalSeconds + $this->policy->permitCleanupReserveSeconds) * 1_000_000;
            DB::insert("INSERT INTO prc_network_permits (id, demand_id, slot_at, expires_at, state, policy_versions, policy_schema_version, health_epochs, health_epochs_schema_version, created_at)
                VALUES (?, ?, ?, ?, 'reserved', ?::jsonb, 1, ?::jsonb, 1, ?)",
                [$permitId, $ticket->id->value, self::date($slot), self::date($expires), json_encode($network->evidence->policies, JSON_THROW_ON_ERROR), json_encode($network->evidence->epochs, JSON_THROW_ON_ERROR), self::date($now)]);
            foreach ($network->budgets as $budget) {
                DB::insert('INSERT INTO prc_network_permit_scopes (permit_id, budget_id) VALUES (?, ?)', [$permitId, $budget->string('id')]);
            }
            DB::update("UPDATE prc_network_demands SET state = 'granted' WHERE id = ?", [$ticket->id->value]);

            return new ScheduledNetworkPermit(new ProcessUuid($permitId), $ticket, self::instant($slot), self::instant($expires));
        });
    }

    public function claimSend(ScheduledNetworkPermit $permit, ExecutionContext|OutboundExecutionContext $context): NetworkSendClaim|NetworkPermitWait|TransportDeferred|TransportBlocked
    {
        $owner = $context->lease();
        $snapshot = $this->owners->snapshot($owner);

        return $this->transaction->run(function () use ($permit, $context, $owner, $snapshot) {
            $state = $this->owners->lock($owner, $snapshot);
            if ($state instanceof TransportBlocked) {
                $this->cancelOwned($permit->ticket, $owner);

                return $state;
            }

            return $this->claimSendLocked($permit, $context, $state);
        });
    }

    /**
     * Joint financial/network integration seam: caller already owns the FULL core prefix and any
     * finance rows in this same connection/transaction. It rolls back both claims on any denial.
     * No capability is returned here; only the outer committed Claimed result may create one.
     */
    public function claimSendLocked(ScheduledNetworkPermit $permit, ExecutionContext|OutboundExecutionContext $context, NetworkOwnerState $state): NetworkSendClaim|NetworkPermitWait|TransportDeferred|TransportBlocked
    {
        CallerTransaction::require('Network send claim');
        $ticket = $permit->ticket;
        $owner = $context->lease();
        $network = $this->network->lock($ticket->plan, $ticket->route, $state);
        if ($network instanceof TransportBlocked) {
            $this->cancelOwned($ticket, $owner);

            return $network;
        }
        $demand = $this->demand($ticket, $owner);
        if ($demand === null) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $row = LedgerRow::one('SELECT * FROM prc_network_permits WHERE id = ? AND demand_id = ? FOR UPDATE', [$permit->id->value, $ticket->id->value]);
        if ($row === null) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $scopeIds = array_map(static fn (LedgerRow $scope): string => $scope->string('budget_id'), LedgerRow::all('SELECT budget_id FROM prc_network_permit_scopes WHERE permit_id = ? ORDER BY budget_id', [$permit->id->value]));
        $expectedIds = array_map(static fn (LedgerRow $budget): string => $budget->string('id'), array_values($network->budgets));
        sort($expectedIds, SORT_STRING);
        if ($scopeIds !== $expectedIds || PermitEvidence::decode($row->string('policy_versions'), $row->int('policy_schema_version')) !== $network->evidence->policies
            || PermitEvidence::decode($row->string('health_epochs'), $row->int('health_epochs_schema_version')) !== $network->evidence->epochs) {
            $this->cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::PolicyReview);
        }
        if ($row->nullableString('authorized_at') !== null) {
            return new NetworkSendClaim(false, new \DateTimeImmutable($row->string('authorized_at')), new \DateTimeImmutable($row->string('expires_at')));
        }
        $now = self::now();
        if (! $this->ownerLive($owner)) {
            $this->cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        if ($row->string('state') !== 'reserved' || $demand->string('state') !== 'granted' || self::micros(new \DateTimeImmutable($row->string('expires_at'))) <= $now) {
            $this->cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $allowed = max(self::micros(new \DateTimeImmutable($row->string('slot_at'))), $this->slot($network, 'send_tat', $now));
        if ($ticket->plan->trafficClass === TrafficClass::StatusRefresh && ($quota = $this->quotaRetry($network, $permit->id->value)) !== null) {
            $this->cancel($ticket);

            return $this->deferred(NetworkDeferralReason::StatusRefreshBudget, $quota);
        }
        if (! $this->fits($context, $state, $now, $allowed, $ticket->plan) || $allowed - $now > $this->policy->maxInlineWaitSeconds * 1_000_000) {
            $this->cancel($ticket);

            return $this->deferred($allowed > $now ? NetworkDeferralReason::RateLimit : NetworkDeferralReason::SliceExhausted, $allowed);
        }
        if ($allowed > $now) {
            return new NetworkPermitWait(new RetryAt(self::instant($allowed)), NetworkDeferralReason::RateLimit);
        }
        // Final DB-clock CAS: locks preserve state, but time may advance during quota/SQL work.
        // No expired permit/lease may be revived even at the last authorization statement.
        $liveOwner = $owner instanceof ExecutionLease
            ? "SELECT 1 FROM prc_process_work WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > n.t"
            : "SELECT 1 FROM prc_outbound_operations o WHERE o.id = ? AND o.generation = ? AND o.owner_token = ? AND o.state = 'open' AND o.lease_until > n.t
                AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id AND w.generation=a.generation
                    WHERE a.id=o.parent_attempt_id AND a.finished_at IS NULL AND w.state='running' AND w.lease_until > n.t))
                AND deadline_at >= n.t + make_interval(secs => ".($ticket->plan->profile->totalSeconds + 15).')';
        $ownerId = $owner instanceof ExecutionLease ? $owner->workId->value->value : $owner->id->value;
        $token = $owner instanceof ExecutionLease ? $owner->token->value : $owner->ownerToken->value;
        $claimed = LedgerRow::one("WITH instant AS MATERIALIZED (SELECT clock_timestamp() AS t)
            UPDATE prc_network_permits SET state = 'sent', authorized_at = n.t,
                expires_at = greatest(expires_at, n.t + make_interval(secs => ?)) FROM instant n
            WHERE id = ? AND state = 'reserved' AND expires_at > n.t AND EXISTS ({$liveOwner})
                AND EXISTS (SELECT 1 FROM prc_network_demands d WHERE d.id = prc_network_permits.demand_id
                    AND d.operation_deadline >= n.t + make_interval(secs => ".($ticket->plan->profile->totalSeconds + 15).'))
            RETURNING authorized_at::text AS authorized_at, expires_at::text AS expires_at',
            [$ticket->plan->profile->totalSeconds + $this->policy->permitCleanupReserveSeconds, $permit->id->value, $ownerId, $owner->generation, $token]);
        if ($claimed === null) {
            $this->cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $now = self::micros(new \DateTimeImmutable($claimed->string('authorized_at')));
        $expires = self::micros(new \DateTimeImmutable($claimed->string('expires_at')));
        foreach ($network->budgets as $budget) {
            DB::update("UPDATE prc_network_budgets SET send_tat = greatest(send_tat, ?::timestamptz) + emission_interval_us * interval '1 microsecond' WHERE id = ?", [self::date($now), $budget->string('id')]);
        }
        DB::insert("INSERT INTO prc_request_attempts (request_id, demand_id, request_seq, target_id, route_id, started_at, sent_at, phase, epochs, epochs_schema_version, artifacts, artifacts_schema_version)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'authorized', ?::jsonb, 1, ?::jsonb, 1)", [$ticket->plan->physicalRequestId->value, $ticket->id->value, $ticket->ticketSeq,
            $ticket->plan->targetId->value, $ticket->route->routeId->value, self::date($now), self::date($now), json_encode($network->evidence->epochs, JSON_THROW_ON_ERROR), json_encode($network->healthAuthorization->toArray(), JSON_THROW_ON_ERROR)]);

        return new NetworkSendClaim(true, self::instant($now), self::instant($expires));
    }

    public function abandon(NetworkDemandTicket $ticket, ExecutionContext|OutboundExecutionContext $context): void
    {
        $this->transaction->run(fn () => $this->cancelOwned($ticket, $context->lease()));
    }

    /** Factual completion, epoch/probe reduction, dedup and release are one short transaction. */
    public function record(ProcessUuid $requestId, HttpAttemptResult $result): TransportDeferred|TransportBlocked|null
    {
        return $this->health->record($requestId, $result);
    }

    /** 429 applies to every alias/route and to already reserved permits; no debt refund. */
    public function cooldown(OutboundRequestPlan $plan, RetryAt $until): void
    {
        $this->transaction->run(function () use ($plan, $until): void {
            $budget = LedgerRow::one('SELECT id FROM prc_network_budgets WHERE scope_key = ? FOR UPDATE', [$plan->url->origin->budgetScopeKey()])
                ?? throw new \LogicException('Missing origin budget.');
            DB::update('UPDATE prc_network_budgets SET not_before = greatest(not_before, ?::timestamptz), tat = greatest(tat, ?::timestamptz) WHERE id = ?',
                [$until->value->format('Y-m-d H:i:s.uP'), $until->value->format('Y-m-d H:i:s.uP'), $budget->string('id')]);
        });
    }

    /** Bounded reaper; preserved permits/requests are history, never DELETE and never refund TAT. */
    public function reap(int $limit = 64): int
    {
        $origins = LedgerRow::all("SELECT b.id FROM prc_network_budgets b WHERE b.scope_key LIKE 'origin:%' AND (
            EXISTS (SELECT 1 FROM prc_network_demands d WHERE d.origin_budget_id = b.id AND d.state = 'queued' AND (
                d.expires_at <= clock_timestamp() OR d.operation_deadline <= clock_timestamp()
                OR (d.operation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_outbound_operations o WHERE o.id = d.operation_id AND o.generation = d.operation_generation AND o.state = 'open' AND o.lease_until > clock_timestamp() AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts pa JOIN prc_process_work pw ON pw.id=pa.work_id AND pw.generation=pa.generation WHERE pa.id=o.parent_attempt_id AND pa.finished_at IS NULL AND pw.state='running' AND pw.lease_until > clock_timestamp()))))
                OR (d.work_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_process_work w WHERE w.id = d.work_id AND w.generation = d.work_generation AND w.state = 'running' AND w.lease_until > clock_timestamp()))))
            OR EXISTS (SELECT 1 FROM prc_network_demands d JOIN prc_network_permits p ON p.demand_id = d.id
                WHERE d.origin_budget_id = b.id AND (
                    (p.state IN ('reserved','sent') AND p.expires_at <= clock_timestamp())
                    OR (p.state = 'reserved' AND (
                        d.operation_deadline <= clock_timestamp()
                        OR (d.operation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_outbound_operations o WHERE o.id = d.operation_id AND o.generation = d.operation_generation AND o.state = 'open' AND o.lease_until > clock_timestamp() AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts pa JOIN prc_process_work pw ON pw.id=pa.work_id AND pw.generation=pa.generation WHERE pa.id=o.parent_attempt_id AND pa.finished_at IS NULL AND pw.state='running' AND pw.lease_until > clock_timestamp()))))
                        OR (d.work_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_process_work w WHERE w.id = d.work_id AND w.generation = d.work_generation AND w.state = 'running' AND w.lease_until > clock_timestamp()))))
                )))
            ORDER BY b.scope_key COLLATE \"C\" LIMIT ?", [max(1, min(64, $limit))]);
        $total = 0;
        foreach ($origins as $origin) {
            $total += $this->transaction->run(function () use ($origin): int {
                LedgerRow::one('SELECT id FROM prc_network_budgets WHERE id = ? FOR UPDATE', [$origin->string('id')]);

                return $this->pruneLocked($origin->string('id'));
            });
        }

        return $total;
    }

    private function demand(NetworkDemandTicket $ticket, ExecutionLease|OutboundOperationHandle $owner): ?LedgerRow
    {
        $row = LedgerRow::one('SELECT * FROM prc_network_demands WHERE id = ? FOR UPDATE', [$ticket->id->value]);

        return $row !== null && $this->matches($row, $owner, $ticket->plan, $ticket->route) ? $row : null;
    }

    private function ownerLive(ExecutionLease|OutboundOperationHandle $owner): bool
    {
        return ($owner instanceof ExecutionLease
            ? LedgerRow::one("SELECT 1 AS live FROM prc_process_work WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > clock_timestamp()", [$owner->workId->value->value, $owner->generation, $owner->token->value])
            : LedgerRow::one("SELECT 1 AS live FROM prc_outbound_operations o WHERE o.id = ? AND o.generation = ? AND o.owner_token = ? AND o.state = 'open' AND o.lease_until > clock_timestamp() AND o.deadline_at > clock_timestamp()
                AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id AND w.generation=a.generation
                    WHERE a.id=o.parent_attempt_id AND a.finished_at IS NULL AND w.state='running' AND w.lease_until > clock_timestamp()))", [$owner->id->value, $owner->generation, $owner->ownerToken->value])) !== null;
    }

    private function matches(LedgerRow $row, ExecutionLease|OutboundOperationHandle $owner, OutboundRequestPlan $plan, NetworkRouteSelection $route): bool
    {
        return $row->string('physical_request_id') === $plan->physicalRequestId->value && $row->string('target_id') === $plan->targetId->value
            && $row->string('route_id') === $route->routeId->value && $row->string('traffic_class') === $plan->trafficClass->value
            && ($owner instanceof ExecutionLease
                ? $row->nullableString('work_id') === $owner->workId->value->value && $row->nullableString('attempt_id') === $owner->attemptId->value && $row->nullableInt('work_generation') === $owner->generation
                : $row->nullableString('operation_id') === $owner->id->value && $row->nullableInt('operation_generation') === $owner->generation);
    }

    private function cancelOwned(NetworkDemandTicket $ticket, ExecutionLease|OutboundOperationHandle $owner): void
    {
        if ($this->demand($ticket, $owner) !== null) {
            $this->cancel($ticket);
        }
    }

    private function cancel(NetworkDemandTicket $ticket): void
    {
        DB::update("UPDATE prc_network_demands SET state = 'cancelled' WHERE id = ? AND state = 'queued'", [$ticket->id->value]);
        DB::update("UPDATE prc_network_permits SET state = 'released' WHERE demand_id = ? AND state = 'reserved'", [$ticket->id->value]);
    }

    private function pruneLocked(string $originId): int
    {
        $expired = DB::update("UPDATE prc_network_demands d SET state = 'expired' WHERE d.id IN (
            SELECT q.id FROM prc_network_demands q WHERE q.origin_budget_id = ? AND q.state = 'queued' AND (
                q.expires_at <= clock_timestamp() OR q.operation_deadline <= clock_timestamp()
                OR (q.operation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_outbound_operations o WHERE o.id = q.operation_id AND o.generation = q.operation_generation AND o.state = 'open' AND o.lease_until > clock_timestamp() AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts pa JOIN prc_process_work pw ON pw.id=pa.work_id AND pw.generation=pa.generation WHERE pa.id=o.parent_attempt_id AND pa.finished_at IS NULL AND pw.state='running' AND pw.lease_until > clock_timestamp()))))
                OR (q.work_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_process_work w WHERE w.id = q.work_id AND w.generation = q.work_generation AND w.state = 'running' AND w.lease_until > clock_timestamp())))
            ORDER BY q.ticket_seq LIMIT 64)", [$originId]);

        return $expired + DB::update("UPDATE prc_network_permits p SET state = 'expired' WHERE p.id IN (
            SELECT p2.id FROM prc_network_permits p2 JOIN prc_network_demands d ON d.id = p2.demand_id
            WHERE d.origin_budget_id = ? AND (
                (p2.state IN ('reserved','sent') AND p2.expires_at <= clock_timestamp())
                OR (p2.state = 'reserved' AND (
                    d.operation_deadline <= clock_timestamp()
                    OR (d.operation_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_outbound_operations o WHERE o.id = d.operation_id AND o.generation = d.operation_generation AND o.state = 'open' AND o.lease_until > clock_timestamp() AND (o.parent_attempt_id IS NULL OR EXISTS (SELECT 1 FROM prc_process_attempts pa JOIN prc_process_work pw ON pw.id=pa.work_id AND pw.generation=pa.generation WHERE pa.id=o.parent_attempt_id AND pa.finished_at IS NULL AND pw.state='running' AND pw.lease_until > clock_timestamp()))))
                    OR (d.work_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM prc_process_work w WHERE w.id = d.work_id AND w.generation = d.work_generation AND w.state = 'running' AND w.lease_until > clock_timestamp()))))
            )
            ORDER BY p2.expires_at LIMIT 64)", [$originId]);
    }

    private function fits(ExecutionContext|OutboundExecutionContext $context, NetworkOwnerState $state, int $now, int $slot, OutboundRequestPlan $plan, ?\DateTimeImmutable $demandDeadline = null): bool
    {
        $needed = max(0, $slot - $now) + $plan->profile->totalSeconds * 1_000_000;

        return $context->canStart(new Duration((int) ceil($needed / 1000)))
            && ($state->deadlineAt === null || self::micros($state->deadlineAt) >= $now + $needed + 15_000_000)
            && ($demandDeadline === null || self::micros($demandDeadline) >= $now + $needed + 15_000_000);
    }

    private function waitOrDefer(NetworkDemandTicket $ticket, LedgerRow $demand, ExecutionContext|OutboundExecutionContext $context, NetworkOwnerState $state, int $now, int $retry, NetworkDeferralReason $reason): NetworkPermitWait|TransportDeferred
    {
        $registered = self::micros(new \DateTimeImmutable($demand->string('registered_at')));
        if ($now >= $registered + $this->policy->maxInlineWaitSeconds * 1_000_000 || ! $this->fits($context, $state, $now, $retry, $ticket->plan, new \DateTimeImmutable($demand->string('operation_deadline')))) {
            $this->cancel($ticket);

            return $this->deferred($reason, max($retry, $now + 1_000_000));
        }
        DB::update('UPDATE prc_network_demands SET renewed_at = ?, expires_at = least(operation_deadline, ?::timestamptz) WHERE id = ? AND expires_at > clock_timestamp()',
            [self::date($now), self::date($now + $this->policy->ticketTtlSeconds * 1_000_000), $ticket->id->value]);

        return new NetworkPermitWait(new RetryAt(self::instant(min($retry, $now + $this->policy->ticketRenewSeconds * 1_000_000))), $reason);
    }

    private function slot(LockedNetwork $network, string $column, int $now): int
    {
        if (! in_array($column, ['tat', 'send_tat'], true)) {
            throw new \LogicException('Invalid GCRA column.');
        }
        $ids = array_map(static fn (LedgerRow $b): string => $b->string('id'), array_values($network->budgets));
        $row = LedgerRow::one("SELECT ceil(extract(epoch FROM max(greatest(?::timestamptz, not_before, {$column} - emission_interval_us * (burst - 1) * interval '1 microsecond'))) * 1000000)::bigint AS slot
            FROM prc_network_budgets WHERE id IN (".LedgerRow::placeholders(count($ids)).')', [self::date($now), ...$ids]);

        return $row?->int('slot') ?? throw new \LogicException('No GCRA authorities.');
    }

    private function capacityAt(LockedNetwork $network): ?int
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

    private function quotaRetry(LockedNetwork $network, ?string $excludePermit = null): ?int
    {
        $interval = $network->origin->int('emission_interval_us');
        $rate = intdiv(59_999_999, $interval) + 1; // inverse of ceil(60e6 / configured integer rate)
        $cap = intdiv($rate * 60 * $this->policy->statusCapacityPercent, 100);
        if ($cap === 0) {
            return self::now() + 3_600_000_000;
        }
        $rows = LedgerRow::all("SELECT ceil(extract(epoch FROM CASE WHEN p.authorized_at IS NOT NULL THEN p.authorized_at + interval '1 hour' ELSE p.expires_at END) * 1000000)::bigint AS release_at
            FROM prc_network_demands d JOIN prc_network_permits p ON p.demand_id = d.id
            WHERE d.origin_budget_id = ? AND d.traffic_class = 'status_refresh' AND (?::uuid IS NULL OR p.id <> ?::uuid)
              AND (p.authorized_at > clock_timestamp() - interval '1 hour' OR (p.state = 'reserved' AND p.expires_at > clock_timestamp()))
            ORDER BY release_at", [$network->origin->string('id'), $excludePermit, $excludePermit]);

        return count($rows) < $cap ? null : $rows[count($rows) - $cap]->int('release_at');
    }

    private static function permit(LedgerRow $row, NetworkDemandTicket $ticket): ScheduledNetworkPermit
    {
        return new ScheduledNetworkPermit(new ProcessUuid($row->string('id')), $ticket, new \DateTimeImmutable($row->string('slot_at')), new \DateTimeImmutable($row->string('expires_at')));
    }

    private function deferred(NetworkDeferralReason $reason, int $at): TransportDeferred
    {
        return new TransportDeferred($reason, new RetryAt(self::instant($at)));
    }

    private static function now(): int
    {
        return LedgerRow::one('SELECT floor(extract(epoch FROM clock_timestamp()) * 1000000)::bigint AS now')?->int('now') ?? throw new \LogicException('DB clock unavailable.');
    }

    private static function micros(\DateTimeImmutable $at): int
    {
        return (int) $at->format('U') * 1_000_000 + (int) $at->format('u');
    }

    private static function instant(int $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($at, 1_000_000), $at % 1_000_000)) ?: throw new \LogicException('Invalid DB instant.');
    }

    private static function date(int $at): string
    {
        return self::instant($at)->format('Y-m-d H:i:s.uP');
    }
}
