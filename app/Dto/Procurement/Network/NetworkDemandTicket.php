<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** Immutable demand identity; poll/renew preserves ticketSeq, expiry never revives it. */
final readonly class NetworkDemandTicket
{
    public function __construct(public ProcessUuid $id, public int $ticketSeq, public OutboundRequestPlan $plan, public NetworkRouteSelection $route) {}
}
