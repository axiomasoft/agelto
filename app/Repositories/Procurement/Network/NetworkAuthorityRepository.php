<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\HealthAuthorization;
use App\Dto\Procurement\Network\NetworkOwnerState;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\PermitEvidence;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\DemandPurpose;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\RouteKind;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\ProbeScheduler;
use Illuminate\Support\Facades\DB;

/**
 * Tier7 order: profile, route, route health, route-target health, target, target health,
 * then budgets by scope_key (C ordering). Health writers use the same health-row subset.
 * No state mutation until the complete set and every predicate have been checked.
 */
final readonly class NetworkAuthorityRepository
{
    public function __construct(private OutboundPolicy $policy, private AdapterHealthRepository $adapters) {}

    public function lock(OutboundRequestPlan $plan, NetworkRouteSelection $selection, NetworkOwnerState $owner): LockedNetwork|TransportBlocked
    {
        CallerTransaction::require('Network authorities');
        $policies = ['scheduling' => $this->policy->policyVersion, 'status_percent' => $this->policy->statusCapacityPercent,
            'request:'.self::fingerprint($plan) => 1, ...$owner->versions];
        $epochs = [];
        // Network tier: profile -> route -> route health -> route-target health -> target -> target health -> budgets.
        // Catalog/retirement uses system control UPDATE, before this subset; health applies only health rows sorted.
        if ($selection->profileId !== null) {
            $profile = LedgerRow::one('SELECT policy_version FROM prc_network_route_profiles WHERE id = ? FOR UPDATE', [$selection->profileId->value]);
            if ($profile === null || LedgerRow::one('SELECT 1 AS present FROM prc_network_route_profile_members WHERE profile_id = ? AND route_id = ?', [$selection->profileId->value, $selection->routeId->value]) === null) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $policies['profile:'.$selection->profileId->value] = $profile->int('policy_version');
        }
        $route = LedgerRow::one('SELECT kind, enabled, credential_version, independence_group, egress_id FROM prc_network_routes WHERE id = ? FOR UPDATE', [$selection->routeId->value]);
        if ($route === null || $route->int('credential_version') !== $selection->credentialVersion || ! $route->bool('enabled') || ($selection->profileId === null && $route->string('kind') !== 'direct')) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $policies['route:'.$selection->routeId->value] = $route->int('credential_version');
        $health = [];
        foreach (['prc_route_health' => ['route_id = ?', [$selection->routeId->value]],
            'prc_route_target_health' => ['route_id = ? AND target_id = ?', [$selection->routeId->value, $plan->targetId->value]]] as $table => [$where, $bindings]) {
            $row = LedgerRow::one("SELECT id, state, health_epoch, reason_code, probe_token, probe_until > clock_timestamp() AS probe_live FROM {$table} WHERE {$where} FOR UPDATE", $bindings);
            if ($row === null) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $health[$table] = $row;
        }
        $target = LedgerRow::one('SELECT platform_id, provider_key, capability, canonical_origin, retired_at::text AS retired_at, policy_version, endpoint_policy::text AS endpoint_policy,
            endpoint_policy_schema_version FROM prc_source_targets WHERE id = ? FOR UPDATE', [$plan->targetId->value]);
        if ($target === null) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        if ($target->nullableString('retired_at') !== null) {
            return new TransportBlocked(NetworkBlockedReason::TargetRetired);
        }
        if (($target->nullableString('provider_key') !== null && ($owner->providerKey?->value !== $target->string('provider_key') || $owner->platformId !== null))
            || ($owner->providerKey !== null && $target->nullableString('provider_key') !== $owner->providerKey->value)
            || ($owner->platformId !== null && $owner->platformId !== $target->nullableInt('platform_id'))
            || $target->string('canonical_origin') !== $plan->url->origin->value() || ! self::profileMatches($target, $plan->profile)) {
            return new TransportBlocked(NetworkBlockedReason::PolicyReview);
        }
        $policies['target:'.$plan->targetId->value] = $target->int('policy_version');
        $targetHealth = LedgerRow::one('SELECT id, state, health_epoch, reason_code, probe_token, probe_until > clock_timestamp() AS probe_live
            FROM prc_target_health WHERE target_id = ? FOR UPDATE', [$plan->targetId->value]);
        if ($targetHealth === null) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $health['prc_target_health'] = $targetHealth;
        $ownedProbes = [];
        foreach ($health as $table => $row) {
            $epochs[$table.':'.$row->string('id')] = $row->int('health_epoch');
            if ($row->string('state') === 'closed') {
                continue;
            }
            if ($owner->purpose !== DemandPurpose::Probe || $owner->probeToken === null
                || $row->string('state') !== 'half_open' || $row->nullableString('probe_token') !== $owner->probeToken
                || ! $row->bool('probe_live')) {
                return new TransportBlocked($row->nullableString('reason_code') === 'credential_hold'
                    ? NetworkBlockedReason::CredentialHold : NetworkBlockedReason::ConfigurationHold);
            }
            $ownedProbes[] = $table.':'.$row->string('id');
        }
        $adapter = $this->adapters->lockForSend($target, $plan, $owner);
        if ($adapter instanceof TransportBlocked) {
            return $adapter;
        }
        if ($adapter !== null) {
            $key = 'prc_adapter_health:'.$adapter->string('id');
            $epochs[$key] = $adapter->int('health_epoch');
            if ($plan->adapterClaim?->canary === true) {
                $ownedProbes[] = $key;
            }
        }
        // A probe owner cannot use a foreign token or an empty probe scope set.
        if ($owner->purpose === DemandPurpose::Probe && $ownedProbes === []) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }
        if ($owner->purpose === DemandPurpose::Probe) {
            sort($ownedProbes, SORT_STRING);
            if ($owner->probeToken === null || ProbeScheduler::tokenScopes($owner->probeToken) !== $ownedProbes) {
                return new TransportBlocked(NetworkBlockedReason::OwnerLost);
            }
        }
        $origin = $plan->url->origin;
        $routeScope = $origin->routeScopeKey($selection->routeId->value);
        $egressScope = $origin->egressScopeKey($route->string('egress_id'));
        $keys = [$origin->budgetScopeKey(), $routeScope];
        if ($owner->purpose === DemandPurpose::Probe) {
            $keys[] = $origin->probeScopeKey();
        }
        $sharedEgress = LedgerRow::one('SELECT 1 AS shared FROM prc_network_routes WHERE egress_id = ? AND id <> ? LIMIT 1',
            [$route->string('egress_id'), $selection->routeId->value]) !== null;
        $hasEgressBudget = LedgerRow::one('SELECT 1 AS ok FROM prc_network_budgets WHERE scope_key = ? LIMIT 1', [$egressScope]) !== null;
        if ($sharedEgress && ! $hasEgressBudget) {
            $ep = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($ep) && is_array($ep['egress_origin_budget'] ?? null)) {
                $eb = OriginBudgetPolicy::fromArray($ep['egress_origin_budget']);
                DB::insert(
                    'INSERT INTO prc_network_budgets (id, scope_key, emission_interval_us, burst, max_inflight, policy_version)
                     VALUES (?, ?, ?, ?, ?, 1) ON CONFLICT (scope_key) DO NOTHING',
                    [ProcessUuid::random()->value, $egressScope, $eb->emissionIntervalUs(), $eb->burst, $eb->maxInflight],
                );
            } else {
                DB::insert(
                    'INSERT INTO prc_network_budgets (id, scope_key, emission_interval_us, burst, max_inflight, policy_version)
                     SELECT ?, ?, emission_interval_us, burst, max_inflight, 1 FROM prc_network_budgets WHERE scope_key = ?
                     ON CONFLICT (scope_key) DO NOTHING',
                    [ProcessUuid::random()->value, $egressScope, $routeScope],
                );
            }
            $hasEgressBudget = true;
        }
        if ($sharedEgress || $hasEgressBudget) {
            $keys[] = $egressScope;
        }
        sort($keys, SORT_STRING);
        $rows = LedgerRow::all('SELECT id, scope_key, emission_interval_us, burst, max_inflight, policy_version,
            tat::text AS tat, send_tat::text AS send_tat, not_before::text AS not_before
            FROM prc_network_budgets WHERE scope_key IN ('.LedgerRow::placeholders(count($keys)).') ORDER BY scope_key COLLATE "C" FOR UPDATE', $keys);
        $budgets = [];
        foreach ($rows as $row) {
            $budgets[$row->string('scope_key')] = $row;
            $policies['budget:'.$row->string('id')] = $row->int('policy_version');
        }
        if (array_keys($budgets) !== $keys) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        ksort($policies, SORT_STRING);
        ksort($epochs, SORT_STRING);

        return new LockedNetwork($budgets, $budgets[$origin->budgetScopeKey()], new PermitEvidence($policies, $epochs),
            new HealthAuthorization(RouteKind::from($route->string('kind')),
                $route->int('credential_version'), $route->nullableString('independence_group'), $owner->probeToken));
    }

    public static function fingerprint(OutboundRequestPlan $plan): string
    {
        return hash('sha256', json_encode([$plan->physicalRequestId->value, $plan->targetId->value, $plan->method,
            hash('sha256', $plan->url->reveal()), $plan->profile->toArray(), $plan->trafficClass->value,
            $plan->idempotency->value, $plan->bodyHash?->hex,
            ...($plan->sendLimit === null ? [] : ['send_limit' => $plan->sendLimit]),
            ...($plan->adapterClaim === null ? [] : ['adapter' => [$plan->adapterClaim->contract->toArray(),
                $plan->adapterClaim->healthId->value, $plan->adapterClaim->epoch, $plan->adapterClaim->ownerId->value,
                $plan->adapterClaim->canary, $plan->adapterClaim->ownerKind->value]])], JSON_THROW_ON_ERROR));
    }

    public static function profileMatches(LedgerRow $target, RequestProfile $profile): bool
    {
        if (! in_array($target->int('endpoint_policy_schema_version'), [1, 2], true)) {
            return false;
        }
        $policy = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy) || ! isset($policy['profiles']) || ! is_array($policy['profiles'])) {
            throw new \LogicException('Invalid endpoint policy.');
        }
        foreach ($policy['profiles'] as $candidate) {
            if (! is_array($candidate)) {
                throw new \LogicException('Invalid endpoint profile.');
            }
            if (RequestProfile::fromArray($candidate) == $profile) {
                return true;
            }
        }

        return false;
    }

    public static function nowMicros(): int
    {
        return LedgerRow::one('SELECT floor(extract(epoch FROM clock_timestamp()) * 1000000)::bigint AS now')?->int('now') ?? throw new \LogicException('DB clock unavailable.');
    }

    public static function nowInstant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(LedgerRow::one('SELECT clock_timestamp()::text AS t')?->string('t') ?? throw new \LogicException('DB clock unavailable.'));
    }

    public static function micros(\DateTimeImmutable $at): int
    {
        return (int) $at->format('U') * 1_000_000 + (int) $at->format('u');
    }

    public static function instant(int $micros): \DateTimeImmutable
    {
        $seconds = intdiv($micros, 1_000_000);
        $utc = new \DateTimeZone('UTC');
        $instant = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $micros - $seconds * 1_000_000), $utc);

        return $instant === false ? throw new \LogicException('Invalid DB instant.') : $instant->setTimezone($utc);
    }

    public static function date(int $micros): string
    {
        return self::instant($micros)->format('Y-m-d H:i:s.uP');
    }

    public static function nullableInstant(?string $value): ?\DateTimeImmutable
    {
        return $value === null ? null : new \DateTimeImmutable($value);
    }
}
