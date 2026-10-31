<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Process\StageCheckpoint;
use App\Enums\Procurement\Process\StageCode;

final readonly class StatusProgress implements StageCheckpoint
{
    public function stage(): StageCode
    {
        return StageCode::StatusRefresh;
    }
}
