<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Runtime binding resolved once before reserve, pinned through claim and wire. */
final readonly class SelectedNetworkRoute
{
    public function __construct(public NetworkRouteSelection $selection, public HttpRouteBinding $binding)
    {
        if ($selection->routeId->value !== $binding->id->value) {
            throw new \InvalidArgumentException('Route binding does not match selected authority.');
        }
    }
}
