<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum ProviderRecoveryMode: string
{
    case ResumeFromReceipt = 'resume_from_receipt';
    case ResumeProviderAsset = 'resume_provider_asset';
    case KnownRetryEligible = 'known_retry_eligible';
    case BlockedUnknown = 'blocked_unknown';
}
