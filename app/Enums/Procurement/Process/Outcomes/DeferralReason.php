<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Outcomes;

enum DeferralReason: string
{
    case NoRoute = 'no_route';
    case TargetCooldown = 'target_cooldown';
    case RateLimit = 'rate_limit';
    case CapacityWait = 'capacity_wait';
    case ItemRetry = 'item_retry';
    case StorageUnavailable = 'storage_unavailable';
    case ConverterUnavailable = 'converter_unavailable';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderBudget = 'provider_budget';
    case WaitingReference = 'waiting_reference';
    case LeaseRecovery = 'lease_recovery';
    case SliceExhausted = 'slice_exhausted';
    case HandlerFault = 'handler_fault';
}
