<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Procurement\Ai\AnalysisSendDenied;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Services\Procurement\Process\Contracts\ProcessClock;

/** Keep the gate's declared reason and retry instant; a slice return retains stage progress. */
final readonly class AnalysisSendPolicy
{
    public function __construct(private ProcessClock $clock) {}

    public function outcome(AnalysisSendDenied $denied, StageCheckpoint $progress): StageOutcome
    {
        $reason = match ($denied->claim->denial) {
            ProviderSendDenial::Network => DeferralReason::NoRoute,
            ProviderSendDenial::Financial => DeferralReason::ProviderBudget,
            ProviderSendDenial::RateLimit => DeferralReason::RateLimit,
            ProviderSendDenial::CapacityWait => DeferralReason::CapacityWait,
            ProviderSendDenial::TargetCooldown => DeferralReason::TargetCooldown,
            ProviderSendDenial::Dependency => DeferralReason::ProviderUnavailable,
            default => null,
        };
        if ($reason !== null) {
            return new StageDeferred($reason, new RetryAt($denied->claim->retryAt ?? $this->clock->dbNow()->value->modify('+30 seconds')));
        }

        return match ($denied->claim->denial) {
            ProviderSendDenial::SliceExhausted => new StagePartial($progress, new MissingParts(['slice_exhausted'])),
            ProviderSendDenial::Paused => new StageBlocked(BlockedReason::Paused),
            ProviderSendDenial::PolicyReview, ProviderSendDenial::AlreadyClaimed => new StageBlocked(BlockedReason::PolicyReview),
            ProviderSendDenial::Revoked => throw new LeaseLost('Provider send ownership revoked.'),
            default => new StageBlocked(BlockedReason::InvalidRecipeConfiguration),
        };
    }
}
