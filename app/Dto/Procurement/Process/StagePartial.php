<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class StagePartial implements StageOutcome
{
    public function __construct(public StageCheckpoint $checkpoint, public MissingParts $missing) {}
}
