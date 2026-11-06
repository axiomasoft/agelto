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
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ExecutionContext;
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

/**
 * PostgreSQL authority for bounded FIFO/GCRA and the distinct actual-send fence.
 */
final readonly class NetworkPermitRepository
{
    public function __construct(
        private NetworkOwnerFence $owners,
        private NetworkAuthorityRepository $network,
        private ProcessTransaction $transaction,
        private OutboundPolicy $policy,
        private HealthRepository $health,
    ) {}

    public function register(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, NetworkRouteSelection $route, ?ProviderKey $provider = null): NetworkDemandTicket|TransportDeferred|TransportBlocked
    {
        $id = ProcessUuid::random()->value;
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
            if (! NetworkPermitSqlHelper::ownerLive($owner)) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $now = NetworkAuthorityRepository::nowMicros();
            NetworkPermitSqlHelper::pruneLocked($network->origin->string('id'));
            $existing = LedgerRow::one('SELECT * FROM prc_network_demands WHERE physical_request_id = ? FOR UPDATE', [$plan->physicalRequestId->value]);
            if ($existing !== null) {
                if (! NetworkPermitSqlHelper::matches($existing, $owner, $plan, $route)) {
                    return new TransportBlocked(NetworkBlockedReason::OwnerLost);
                }
                if (! in_array($existing->string('state'), ['queued', 'granted'], true)) {
                    return NetworkPermitSqlHelper::deferred(NetworkDeferralReason::CapacityWait, $now + 1_000_000);
                }

                return new NetworkDemandTicket(new ProcessUuid($existing->string('id')), $existing->int('ticket_seq'), $plan, $route);
            }
            if (! NetworkPermitSqlHelper::fits($context, $state, $now, $now, $plan)) {
                return NetworkPermitSqlHelper::deferred(NetworkDeferralReason::SliceExhausted, $now + 1_000_000);
            }
            $queue = LedgerRow::one("SELECT count(*) AS n, min(expires_at)::text AS next FROM prc_network_demands WHERE origin_budget_id = ? AND state = 'queued'", [$network->origin->string('id')]);
            if ($queue !== null && $queue->int('n') >= $this->policy->maxPendingDemandsPerOrigin) {
                return new TransportDeferred(NetworkDeferralReason::CapacityWait, new RetryAt(new \DateTimeImmutable($queue->string('next'))));
            }
            $pending = NetworkPermitSqlHelper::expireQueuedForOwner($owner);
            if ($pending !== null) {
                return new TransportDeferred(NetworkDeferralReason::CapacityWait, new RetryAt(new \DateTimeImmutable($pending->string('expires_at'))));
            }
            $deadline = $state->deadlineAt ?? NetworkAuthorityRepository::instant($now + $context->remainingSlice()->milliseconds * 1000);
            $expires = min(NetworkAuthorityRepository::micros($deadline), $now + $this->policy->ticketTtlSeconds * 1_000_000);
            $row = NetworkPermitSqlHelper::insertDemand($id, $plan, $route, $owner, $state, $network->origin->string('id'), $now, $expires, $deadline);

            return new NetworkDemandTicket(new ProcessUuid($id), $row->int('ticket_seq'), $plan, $route);
        });
    }

    public function reserve(NetworkDemandTicket $ticket, ExecutionContext|OutboundExecutionContext $context, ?ProviderKey $provider = null): ScheduledNetworkPermit|NetworkPermitWait|TransportDeferred|TransportBlocked
    {
        $permitId = ProcessUuid::random()->value;
        $owner = $context->lease();
        $snapshot = $this->owners->snapshot($owner, $provider);

        return $this->transaction->run(function () use ($ticket, $context, $owner, $snapshot, $permitId, $provider) {
            $state = $this->owners->lock($owner, $snapshot, $provider);
            if ($state instanceof TransportBlocked) {
                NetworkPermitSqlHelper::cancelOwned($ticket, $owner);

                return $state;
            }
            $network = $this->network->lock($ticket->plan, $ticket->route, $state);
            if ($network instanceof TransportBlocked) {
                NetworkPermitSqlHelper::cancelOwned($ticket, $owner);

                return $network;
            }
            if (! NetworkPermitSqlHelper::ownerLive($owner)) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $now = NetworkAuthorityRepository::nowMicros();
            NetworkPermitSqlHelper::pruneLocked($network->origin->string('id'));
            $demand = NetworkPermitSqlHelper::demand($ticket, $owner);
            if ($demand === null) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $existing = LedgerRow::one('SELECT * FROM prc_network_permits WHERE demand_id = ? FOR UPDATE', [$ticket->id->value]);
            if ($existing !== null && $existing->string('state') === 'reserved') {
                return NetworkPermitSqlHelper::permit($existing, $ticket);
            }
            if ($demand->string('state') !== 'queued') {
                return NetworkPermitSqlHelper::deferred(NetworkDeferralReason::CapacityWait, $now + 1_000_000);
            }
            $originId = $network->origin->string('id');
            $head = LedgerRow::one("SELECT id FROM prc_network_demands WHERE origin_budget_id = ? AND state = 'queued' ORDER BY ticket_seq LIMIT 1", [$originId]);
            $capacityAt = NetworkPermitSqlHelper::capacityAt($network);
            if ($head?->string('id') !== $ticket->id->value || $capacityAt !== null) {
                return NetworkPermitSqlHelper::waitOrDefer($ticket, $demand, $context, $state, $now, $capacityAt ?? $now + $this->policy->ticketTtlSeconds * 1_000_000, NetworkDeferralReason::CapacityWait, $this->policy);
            }
            if ($ticket->plan->trafficClass === TrafficClass::StatusRefresh && ($quota = NetworkPermitSqlHelper::quotaRetry($network, $this->policy->statusCapacityPercent)) !== null) {
                NetworkPermitSqlHelper::cancel($ticket);

                return NetworkPermitSqlHelper::deferred(NetworkDeferralReason::StatusRefreshBudget, $quota);
            }
            $slot = NetworkPermitSqlHelper::slot($network, 'tat', $now);
            $opDeadline = new \DateTimeImmutable($demand->string('operation_deadline'));
            if ($slot - $now > $this->policy->maxInlineWaitSeconds * 1_000_000 || ! NetworkPermitSqlHelper::fits($context, $state, $now, $slot, $ticket->plan, $opDeadline)) {
                NetworkPermitSqlHelper::cancel($ticket);

                return NetworkPermitSqlHelper::deferred(NetworkPermitSqlHelper::fits($context, $state, $now, $now, $ticket->plan, $opDeadline) ? NetworkDeferralReason::RateLimit : NetworkDeferralReason::SliceExhausted, $slot);
            }
            $expires = $slot + ($ticket->plan->profile->totalSeconds + $this->policy->permitCleanupReserveSeconds) * 1_000_000;

            return NetworkPermitSqlHelper::insertReservedPermit($permitId, $ticket, $network, $slot, $expires, $now);
        });
    }

    public function claimSend(ScheduledNetworkPermit $permit, ExecutionContext|OutboundExecutionContext $context): NetworkSendClaim|NetworkPermitWait|TransportDeferred|TransportBlocked
    {
        $owner = $context->lease();
        $snapshot = $this->owners->snapshot($owner);

        return $this->transaction->run(function () use ($permit, $context, $owner, $snapshot) {
            $state = $this->owners->lock($owner, $snapshot);
            if ($state instanceof TransportBlocked) {
                NetworkPermitSqlHelper::cancelOwned($permit->ticket, $owner);

                return $state;
            }

            return $this->claimSendLocked($permit, $context, $state);
        });
    }

    public function claimSendLocked(ScheduledNetworkPermit $permit, ExecutionContext|OutboundExecutionContext $context, NetworkOwnerState $state): NetworkSendClaim|NetworkPermitWait|TransportDeferred|TransportBlocked
    {
        CallerTransaction::require('Network send claim');
        $ticket = $permit->ticket;
        $owner = $context->lease();
        $network = $this->network->lock($ticket->plan, $ticket->route, $state);
        if ($network instanceof TransportBlocked) {
            NetworkPermitSqlHelper::cancelOwned($ticket, $owner);

            return $network;
        }
        $demand = NetworkPermitSqlHelper::demand($ticket, $owner);
        if ($demand === null) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $row = LedgerRow::one('SELECT * FROM prc_network_permits WHERE id = ? AND demand_id = ? FOR UPDATE', [$permit->id->value, $ticket->id->value]);
        if ($row === null) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        if (! NetworkPermitSqlHelper::hasValidPermitEvidence($permit->id->value, $row, $network)) {
            NetworkPermitSqlHelper::cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::PolicyReview);
        }
        if ($row->nullableString('authorized_at') !== null) {
            return new NetworkSendClaim(false, new \DateTimeImmutable($row->string('authorized_at')), new \DateTimeImmutable($row->string('expires_at')));
        }
        $now = NetworkAuthorityRepository::nowMicros();
        if (! NetworkPermitSqlHelper::ownerLive($owner)
            || $row->string('state') !== 'reserved' || $demand->string('state') !== 'granted'
            || NetworkAuthorityRepository::micros(new \DateTimeImmutable($row->string('expires_at'))) <= $now) {
            NetworkPermitSqlHelper::cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $allowed = max(NetworkAuthorityRepository::micros(new \DateTimeImmutable($row->string('slot_at'))), NetworkPermitSqlHelper::slot($network, 'send_tat', $now));
        if ($ticket->plan->trafficClass === TrafficClass::StatusRefresh && ($quota = NetworkPermitSqlHelper::quotaRetry($network, $this->policy->statusCapacityPercent, $permit->id->value)) !== null) {
            NetworkPermitSqlHelper::cancel($ticket);

            return NetworkPermitSqlHelper::deferred(NetworkDeferralReason::StatusRefreshBudget, $quota);
        }
        if (! NetworkPermitSqlHelper::fits($context, $state, $now, $allowed, $ticket->plan) || $allowed - $now > $this->policy->maxInlineWaitSeconds * 1_000_000) {
            NetworkPermitSqlHelper::cancel($ticket);

            return NetworkPermitSqlHelper::deferred($allowed > $now ? NetworkDeferralReason::RateLimit : NetworkDeferralReason::SliceExhausted, $allowed);
        }
        if ($allowed > $now) {
            return new NetworkPermitWait(new RetryAt(NetworkAuthorityRepository::instant($allowed)), NetworkDeferralReason::RateLimit);
        }
        $claimed = NetworkPermitSqlHelper::authorizeSendCas($permit, $owner, $this->policy->permitCleanupReserveSeconds);
        if ($claimed === null) {
            NetworkPermitSqlHelper::cancel($ticket);

            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        $now = NetworkAuthorityRepository::micros(new \DateTimeImmutable($claimed->string('authorized_at')));
        $expires = NetworkAuthorityRepository::micros(new \DateTimeImmutable($claimed->string('expires_at')));
        NetworkPermitSqlHelper::recordAuthorizedAttempt($ticket, $network, $now);

        return new NetworkSendClaim(true, NetworkAuthorityRepository::instant($now), NetworkAuthorityRepository::instant($expires));
    }

    public function abandon(NetworkDemandTicket $ticket, ExecutionContext|OutboundExecutionContext $context): void
    {
        $this->transaction->run(fn () => NetworkPermitSqlHelper::cancelOwned($ticket, $context->lease()));
    }

    public function record(ProcessUuid $requestId, HttpAttemptResult $result): TransportDeferred|TransportBlocked|null
    {
        return $this->health->record($requestId, $result);
    }

    public function cooldown(OutboundRequestPlan $plan, RetryAt $until): void
    {
        $this->transaction->run(function () use ($plan, $until): void {
            $budget = LedgerRow::one('SELECT id FROM prc_network_budgets WHERE scope_key = ? FOR UPDATE', [$plan->url->origin->budgetScopeKey()])
                ?? throw new \LogicException('Missing origin budget.');
            DB::update('UPDATE prc_network_budgets SET not_before = greatest(not_before, ?::timestamptz), tat = greatest(tat, ?::timestamptz) WHERE id = ?',
                [$until->value->format('Y-m-d H:i:s.uP'), $until->value->format('Y-m-d H:i:s.uP'), $budget->string('id')]);
        });
    }

    public function reap(int $limit = 64): int
    {
        $total = 0;
        foreach (NetworkPermitSqlHelper::reapableOrigins($limit) as $origin) {
            $total += $this->transaction->run(function () use ($origin): int {
                LedgerRow::one('SELECT id FROM prc_network_budgets WHERE id = ? FOR UPDATE', [$origin->string('id')]);

                return NetworkPermitSqlHelper::pruneLocked($origin->string('id'));
            });
        }

        return $total;
    }
}
