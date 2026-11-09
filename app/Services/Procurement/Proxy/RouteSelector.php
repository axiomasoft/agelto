<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;

/** Scoped selector built by ProxyResolver; selection is advisory, grant/send revalidate under locks. */
final readonly class RouteSelector implements OutboundRouteSelector
{
    public function __construct(private RoutePolicy $policy, private HealthRepository $health, private ?ProbeClaim $probe = null) {}

    public function select(OutboundRequestPlan $plan, RetryRoutePreference $preference): SelectedNetworkRoute|TransportDeferred|TransportBlocked
    {
        $target = LedgerRow::one('SELECT retired_at::text AS retired_at FROM prc_source_targets WHERE id = ?', [$plan->targetId->value]);
        if ($target === null) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        if ($target->nullableString('retired_at') !== null) {
            return new TransportBlocked(NetworkBlockedReason::TargetRetired);
        }
        $now = HealthRepository::now();
        $eligible = [];
        $withinBudget = [];
        $next = null;
        $rateLimitNext = null;
        $probeDue = false;
        $credentialHold = false;
        foreach ($this->policy->bindings as $id => $binding) {
            if (! in_array($id, $preference->triedRouteIds, true) && count($preference->triedRouteIds) >= $preference->maxDistinctRoutes) {
                continue;
            }
            $route = LedgerRow::one('SELECT enabled, kind, egress_id FROM prc_network_routes WHERE id = ?', [$id]);
            if ($route === null || ! $route->bool('enabled') || ($route->string('kind') === 'direct') !== ($binding->proxy() === '')) {
                continue;
            }
            if ($this->policy->profileId !== null && LedgerRow::one('SELECT route_id FROM prc_network_route_profile_members WHERE profile_id = ? AND route_id = ?', [$this->policy->profileId->value, $id]) === null) {
                continue;
            }
            $ready = true;
            $held = false;
            $routeDue = false;
            $routeNext = null;
            $snapshots = $this->health->snapshots($id, $plan->targetId->value);
            if ($snapshots === []) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            foreach ($snapshots as $key => $row) {
                if ($row->state === CircuitState::Closed) {
                    continue;
                }
                if ($this->probe !== null && ($this->probe->epochs[$key] ?? null) === $row->epoch && $row->probeToken === $this->probe->token->value
                    && $row->state === CircuitState::HalfOpen && $row->probeUntil !== null && $row->probeUntil > $now) {
                    continue;
                }
                $ready = false;
                if ($this->health->credentialHeld($row, $id)) {
                    $held = true;

                    continue;
                }
                $due = $row->state === CircuitState::HalfOpen ? $row->probeUntil : $row->openUntil;
                if ($due === null || $due <= $now) {
                    $routeDue = true;
                } else {
                    $routeNext = $routeNext === null ? $due : max($routeNext, $due);
                }
            }
            if ($held) {
                $credentialHold = true;

                continue;
            }
            if ($routeNext !== null) {
                $next = $next === null ? $routeNext : min($next, $routeNext);
            } elseif ($routeDue) {
                $probeDue = true;
            }
            if ($ready) {
                $budgetState = LedgerRow::one(
                    "SELECT
                        max(CASE WHEN b.not_before > clock_timestamp() THEN b.not_before ELSE NULL END)::text AS cooldown_until,
                        max(CASE WHEN (b.tat - b.emission_interval_us * (b.burst - 1) * interval '1 microsecond') > clock_timestamp()
                            THEN (b.tat - b.emission_interval_us * (b.burst - 1) * interval '1 microsecond') ELSE NULL END)::text AS slot_until,
                        bool_or((
                            SELECT count(*) FROM prc_network_permit_scopes s
                            JOIN prc_network_permits p ON p.id = s.permit_id
                            WHERE s.budget_id = b.id AND p.state IN ('reserved', 'sent') AND p.expires_at > clock_timestamp()
                        ) >= b.max_inflight) AS inflight_full
                    FROM prc_network_budgets b
                    WHERE b.scope_key IN (?, ?)",
                    [$plan->url->origin->routeScopeKey($id), $plan->url->origin->egressScopeKey($route->string('egress_id'))],
                );
                $cooldownUntil = $budgetState?->nullableString('cooldown_until');
                if ($cooldownUntil !== null) {
                    $cooldownInstant = new \DateTimeImmutable($cooldownUntil);
                    $rateLimitNext = $rateLimitNext === null ? $cooldownInstant : min($rateLimitNext, $cooldownInstant);

                    continue;
                }
                $eligible[$id] = $binding;
                if ($budgetState?->nullableString('slot_until') === null && ! ($budgetState?->bool('inflight_full') ?? false)) {
                    $withinBudget[$id] = true;
                }
            }
        }
        if ($eligible === [] && $rateLimitNext !== null) {
            return new TransportDeferred(NetworkDeferralReason::RateLimit, new RetryAt($rateLimitNext));
        }
        if ($eligible === [] && $credentialHold && ! $probeDue && $next === null) {
            return new TransportBlocked(NetworkBlockedReason::CredentialHold);
        }
        if ($eligible === []) {
            return new TransportDeferred($probeDue ? NetworkDeferralReason::TargetCooldown : NetworkDeferralReason::NoRoute,
                new RetryAt($probeDue ? $now->modify('+1 second') : ($next ?? $now->modify('+30 seconds'))));
        }
        if ($preference->preferUntried) {
            $fresh = array_diff_key($eligible, array_fill_keys($preference->triedRouteIds, true));
            if ($fresh !== []) {
                $eligible = $fresh;
            }
        } elseif ($preference->currentRoute !== null && isset($eligible[$preference->currentRoute->value])) {
            $eligible = [$preference->currentRoute->value => $eligible[$preference->currentRoute->value]];
        }
        $budgetReady = array_intersect_key($eligible, $withinBudget);
        if ($budgetReady !== []) {
            $eligible = $budgetReady;
        }
        if ($this->policy->random && count($eligible) > 1) {
            $keys = array_keys($eligible);
            $chosen = $keys[random_int(0, count($keys) - 1)];

            return new SelectedNetworkRoute(new NetworkRouteSelection($eligible[$chosen]->id, $this->policy->profileId, $eligible[$chosen]->credentialVersion), $eligible[$chosen]);
        }
        // Least-recent actual authorization, preserving explicit policy order on ties.
        $chosen = array_key_first($eligible);
        $oldest = null;
        foreach ($eligible as $id => $binding) {
            $last = LedgerRow::one('SELECT max(sent_at)::text AS t FROM prc_request_attempts WHERE route_id = ?', [$id])?->nullableString('t');
            if ($last === null) {
                $chosen = $id;
                break;
            }
            if ($oldest === null || $last < $oldest) {
                $chosen = $id;
                $oldest = $last;
            }
        }

        return new SelectedNetworkRoute(new NetworkRouteSelection($eligible[$chosen]->id, $this->policy->profileId, $eligible[$chosen]->credentialVersion), $eligible[$chosen]);
    }
}
