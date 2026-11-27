<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Control;

use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class StatusProgress implements StageCheckpoint
{
    public function stage(): StageCode
    {
        return StageCode::StatusRefresh;
    }
}
