<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;

final readonly class WorkStateCount
{
    public function __construct(
        public StageCode $stage,
        public WorkState $state,
        public DeferralReason|BlockedReason|null $reason,
        public int $count,
    ) {
        ProcessValue::nonNegative($count);
    }
}
