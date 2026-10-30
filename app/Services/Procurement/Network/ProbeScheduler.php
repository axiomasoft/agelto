<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\CircuitState as C;
use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Repositories\Procurement\Network\NetworkAuthorityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Construction-only before P2.6: a bounded typed probe owner still uses ordinary FIFO budgets. */
final readonly class ProbeScheduler
{
    public function __construct(private ProcessTransaction $transaction, private NetworkOwnerFence $owners,
        private HealthRepository $health, private HealthPolicy $policy, private OutboundPolicy $outbound,
        private AdapterHealthRepository $adapters) {}

    public function claim(OutboundRequestPlan $plan, OutboundExecutionContext $context, NetworkRouteSelection $selection): ProbeClaim|TransportBlocked|TransportDeferred
    {
        return $this->transaction->run(function () use ($plan, $context, $selection) {
            $owner = $this->owners->lock($context->lease(), null);
            if ($owner instanceof TransportBlocked) {
                return $owner;
            }
            if ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken === null || $owner->deadlineAt === null) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            // Serializes accidental token reuse across different operation rows as well.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$owner->probeToken]);
            if ($selection->profileId !== null) {
                LedgerRow::one('SELECT id FROM prc_network_route_profiles WHERE id = ? FOR UPDATE', [$selection->profileId->value]);
                if (LedgerRow::one('SELECT route_id FROM prc_network_route_profile_members WHERE profile_id = ? AND route_id = ?', [$selection->profileId->value, $selection->routeId->value]) === null) {
                    return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
                }
            }
            $rows = $this->health->snapshots($selection->routeId->value, $plan->targetId->value, true);
            if ($rows === []) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $route = LedgerRow::one('SELECT enabled, kind, credential_version FROM prc_network_routes WHERE id = ?', [$selection->routeId->value]);
            $target = LedgerRow::one('SELECT platform_id, provider_key, capability, retired_at::text AS retired_at, canonical_origin, endpoint_policy, endpoint_policy_schema_version FROM prc_source_targets WHERE id = ?', [$plan->targetId->value]);
            if ($route === null || $route->int('credential_version') !== $selection->credentialVersion || ! $route->bool('enabled') || ($selection->profileId === null && $route->string('kind') !== 'direct')
                || $target === null || $target->nullableString('retired_at') !== null || $target->string('canonical_origin') !== $plan->url->origin->value()
                || ($owner->platformId !== null && $owner->platformId !== $target->nullableInt('platform_id'))
                || ($owner->providerKey?->value !== $target->nullableString('provider_key'))
                || ! NetworkAuthorityRepository::profileMatches($target, $plan->profile)) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $live = LedgerRow::one('SELECT lease_until > clock_timestamp() AS live FROM prc_outbound_operations WHERE id = ?', [$context->lease()->id->value]);
            if ($live === null || ! $live->bool('live')) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $now = HealthRepository::now();
            $until = $now->modify('+'.($plan->profile->totalSeconds + $this->outbound->maxInlineWaitSeconds + $this->policy->probeReserve).' seconds');
            if ($until > $owner->deadlineAt) {
                return new TransportDeferred(NetworkDeferralReason::SliceExhausted, new RetryAt($now->modify('+1 second')));
            }
            $needed = [];
            foreach ($rows as $key => $row) {
                if ($row->probeToken === $owner->probeToken && ($row->probeUntil === null || $row->probeUntil <= $now)) {
                    return new TransportBlocked(NetworkBlockedReason::OwnerLost);
                }
                if ($row->state === C::Closed) {
                    continue;
                }
                if ($this->health->credentialHeld($row, $selection->routeId->value)) {
                    return new TransportBlocked(NetworkBlockedReason::CredentialHold);
                }
                if ($row->state === C::Open && $row->openUntil !== null && $row->openUntil > $now) {
                    return new TransportDeferred(NetworkDeferralReason::TargetCooldown, new RetryAt($row->openUntil));
                }
                if ($row->state === C::HalfOpen && $row->probeUntil !== null && $row->probeUntil > $now) {
                    if ($row->probeToken !== $owner->probeToken) {
                        return new TransportDeferred(NetworkDeferralReason::TargetCooldown, new RetryAt($row->probeUntil));
                    }
                    $until = min($until, $row->probeUntil);
                }
                $needed[$key] = $row;
            }
            $adapter = $this->adapters->lockForSend($target, $plan, $owner);
            if ($adapter instanceof TransportBlocked) {
                return $adapter;
            }
            $adapterKey = $plan->adapterClaim?->canary === true && $adapter !== null
                ? $plan->adapterClaim->epochKey() : null;
            if ($adapterKey !== null) {
                $until = min($until, new \DateTimeImmutable($adapter->string('probe_until')));
            }
            if ($needed === [] && $adapterKey === null) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $owned = self::tokenScopes($owner->probeToken);
            foreach ($owned as $key) {
                if (! isset($needed[$key]) && $key !== $adapterKey) {
                    return new TransportBlocked(NetworkBlockedReason::OwnerLost);
                }
            }
            // A consumed operation/token cannot be revived for another probe or another scope set.
            if ($owned === [] && LedgerRow::one('SELECT id FROM prc_network_demands WHERE operation_id = ? LIMIT 1', [$context->lease()->id->value]) !== null) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
            $epochs = $adapterKey === null ? [] : [$adapterKey => $plan->adapterClaim->epoch];
            foreach ($needed as $key => $row) {
                $epoch = $row->epoch + ($row->probeToken === $owner->probeToken && $row->probeUntil !== null && $row->probeUntil > $now ? 0 : 1);
                DB::update('UPDATE '.$row->scope->value." SET state = 'half_open', health_epoch = ?, state_version = state_version + 1,
                    probe_token = ?, probe_until = ?, reason_code = NULL, open_until = NULL WHERE id = ?",
                    [$epoch, $owner->probeToken, $until->format('Y-m-d H:i:s.uP'), $row->id]);
                $epochs[$key] = $epoch;
            }
            ksort($epochs, SORT_STRING);

            return new ProbeClaim(new ProcessUuid($owner->probeToken), $epochs, $until);
        });
    }

    /** @return list<string> Token scope set is implicit in the existing authoritative health rows. */
    public static function tokenScopes(string $token): array
    {
        $keys = [];
        foreach (HealthScope::cases() as $scope) {
            foreach (LedgerRow::all('SELECT id FROM '.$scope->value.' WHERE probe_token = ?', [$token]) as $row) {
                $keys[] = $scope->value.':'.$row->string('id');
            }
        }
        foreach (LedgerRow::all('SELECT id FROM prc_adapter_health WHERE probe_token = ?', [$token]) as $row) {
            $keys[] = 'prc_adapter_health:'.$row->string('id');
        }
        sort($keys, SORT_STRING);

        return $keys;
    }
}
