<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;

/** Reclaims the exact composite scope for each tab/redirect, under one full validator owner. */
final readonly class AdapterCanaryRouteSelector implements OutboundRouteSelector
{
    public function __construct(private OutboundExecutionContext $context, private RoutePolicy $routes,
        private ProbeScheduler $probes, private ProxyResolver $resolver) {}

    public function select(OutboundRequestPlan $plan, RetryRoutePreference $preference): SelectedNetworkRoute|TransportDeferred|TransportBlocked
    {
        $last = new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        foreach ($this->routes->bindings as $binding) {
            $claim = $this->probes->claim($plan, $this->context,
                new NetworkRouteSelection($binding->id, $this->routes->profileId, $binding->credentialVersion));
            if (! $claim instanceof ProbeClaim) {
                $last = $claim;

                continue;
            }

            return $this->resolver->outboundRoutes(new RoutePolicy([$binding], $this->routes->profileId), $claim)->select($plan, $preference);
        }

        return $last;
    }
}
