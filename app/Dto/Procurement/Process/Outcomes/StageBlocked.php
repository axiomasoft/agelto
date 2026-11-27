<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

use App\Enums\Procurement\Process\Outcomes\BlockedReason;

final readonly class StageBlocked implements StageOutcome
{
    public function __construct(public BlockedReason $reason) {}
}
