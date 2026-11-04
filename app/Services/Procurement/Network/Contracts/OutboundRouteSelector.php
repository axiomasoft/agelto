<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network\Contracts;

use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;

/** Selects only explicit route-profile members; the send gate revalidates their authority. */
interface OutboundRouteSelector
{
    public function select(OutboundRequestPlan $plan, RetryRoutePreference $preference): SelectedNetworkRoute|TransportDeferred|TransportBlocked;
}
