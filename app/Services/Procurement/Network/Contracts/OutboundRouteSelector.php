<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;

/** P2.4 routing supplies explicit membership; P2.6 activates the sole runtime binding. */
interface OutboundRouteSelector
{
    public function select(OutboundRequestPlan $plan, RetryRoutePreference $preference): SelectedNetworkRoute|TransportDeferred|TransportBlocked;
}
