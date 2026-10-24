<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\ItemFailureReason;

final readonly class StageFailedItem implements StageOutcome
{
    public function __construct(public ItemFailureReason $reason, public ArtifactRef $evidence) {}
}
