<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Состояние FIFO demand ticket; совпадает с CHECK `prc_network_demands.state`. */
enum DemandState: string
{
    case Queued = 'queued';
    case Granted = 'granted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
