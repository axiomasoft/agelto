<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ProcessValue;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Enums\Procurement\Process\StageCode;

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
