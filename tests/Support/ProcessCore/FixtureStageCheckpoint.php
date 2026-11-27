<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class FixtureStageCheckpoint implements StageCheckpoint
{
    public function __construct(public int $position, public StageCode $stageCode = StageCode::Listing)
    {
        ProcessValue::nonNegative($position);
    }

    public function stage(): StageCode
    {
        return $this->stageCode;
    }
}
