<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/**
 * Причина TransportDeferred с осмысленным RetryAt (platform-transport#s05/#s06). no_route только
 * при отсутствии допустимого route; ожидание rate/capacity — rate_limit/capacity_wait.
 */
enum NetworkDeferralReason: string
{
    case NoRoute = 'no_route';
    case RateLimit = 'rate_limit';
    case CapacityWait = 'capacity_wait';
    case TargetCooldown = 'target_cooldown';
    case StatusRefreshBudget = 'status_refresh_budget';
    case AttemptsExhausted = 'attempts_exhausted';
    case SliceExhausted = 'slice_exhausted';
    case DependencyUnavailable = 'dependency_unavailable';
}
