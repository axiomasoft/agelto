<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/** Provisional scheduled network slot, with no send authority. */
final readonly class ReservedProviderNetwork
{
    public function __construct(public ScheduledNetworkPermit $permit, public SelectedNetworkRoute $route, public ResolvedUrl $resolved) {}
}
