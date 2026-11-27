<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

/**
 * Liveness (loop обновляет heartbeat) и readiness (нет текущей dependency/processing ошибки)
 * различны: свежий heartbeat с last_error_code — Degraded, отсутствие свежего heartbeat — Down.
 */
enum DaemonHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Down = 'down';
}
