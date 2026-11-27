<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Circuit state route/route-target/target health rows. Начальное unknown: обычный backlog не
 * получает permit до успешного owned probe.
 */
enum CircuitState: string
{
    case Unknown = 'unknown';
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
