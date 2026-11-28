<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum ProviderSendDenial: string
{
    case AlreadyClaimed = 'already_claimed';
    case Revoked = 'revoked';
    case Network = 'network';
    case Financial = 'financial';
    case WireMismatch = 'wire_mismatch';
    case Unsupported = 'unsupported';
    case Dependency = 'dependency';
    case SliceExhausted = 'slice_exhausted';
    case RateLimit = 'rate_limit';
    case CapacityWait = 'capacity_wait';
    case TargetCooldown = 'target_cooldown';
    case Paused = 'paused';
    case PolicyReview = 'policy_review';
    case Configuration = 'configuration';
}
