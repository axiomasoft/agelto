<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** open → closed (finally) | expired (reaper CAS); expired token никогда не оживает. */
enum OutboundOperationState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Expired = 'expired';
}
