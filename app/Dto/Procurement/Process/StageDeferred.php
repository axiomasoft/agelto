<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\DeferralReason;

final readonly class StageDeferred implements StageOutcome
{
    public function __construct(public DeferralReason $reason, public RetryAt $retryAt) {}
}
