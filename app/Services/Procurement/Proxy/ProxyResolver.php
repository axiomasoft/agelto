<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxy;

use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Process\DatabaseFailureClassifier;

/** Sole route entry. Exact profile membership, with no implicit direct fallback or health writer. */
final readonly class ProxyResolver
{
    public function __construct(private RouteRepository $routes, private HealthRepository $health, private OutboundPolicy $policy) {}

    public function outboundRoutes(RoutePolicy $policy, ?ProbeClaim $probe = null): OutboundRouteSelector
    {
        return new RouteSelector($policy, $this->health, $probe);
    }

    public function profile(?ProcessUuid $id = null, bool $provider = false): RoutePolicy
    {
        try {
            $profile = $this->routes->profile(id: $id, key: $provider ? $this->policy->providerRouteProfile : $this->policy->defaultRouteProfile);
            if ($profile === null) {
                throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
            }

            return new RoutePolicy(bindings: $this->routes->bindings($profile), profileId: $profile, random: true);
        } catch (\Throwable $failure) {
            if (! DatabaseFailureClassifier::isTransient($failure)
                && ! $failure instanceof DependencyUnavailable) {
                throw $failure;
            }
            throw OutboundUnavailable::dependency();
        }
    }
}
