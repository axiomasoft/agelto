<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;

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
