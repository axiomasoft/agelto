<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\BlockedReason;

final readonly class StageBlocked implements StageOutcome
{
    public function __construct(public BlockedReason $reason) {}
}
