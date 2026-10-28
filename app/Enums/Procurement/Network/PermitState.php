<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Scheduled permit: reserved — обещание slot, sent — fenced authorization (bp-rate-gcra). */
enum PermitState: string
{
    case Reserved = 'reserved';
    case Sent = 'sent';
    case Released = 'released';
    case Expired = 'expired';
}
