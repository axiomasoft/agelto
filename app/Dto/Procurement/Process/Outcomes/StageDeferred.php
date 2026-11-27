<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;

final readonly class StageDeferred implements StageOutcome
{
    public function __construct(public DeferralReason $reason, public RetryAt $retryAt) {}
}
