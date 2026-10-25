<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/**
 * Закрытый набор исходов WorkStore::claim (execution#s03). Только Claimed разрешает handler;
 * остальные — ACK доставки без handler/send.
 */
enum ClaimStatus: string
{
    case Claimed = 'claimed';
    case StaleDelivery = 'stale_delivery';
    case DuplicateDelivery = 'duplicate_delivery';
    case Paused = 'paused';
    case HandlerHeld = 'handler_held';
    case CapacityWait = 'capacity_wait';
    case ClaimExpired = 'claim_expired';
    case WorkTerminal = 'work_terminal';
    case InvariantViolation = 'invariant_violation';
}
