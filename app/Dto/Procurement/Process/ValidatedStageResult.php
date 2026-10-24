<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

interface ValidatedStageResult
{
    public function stage(): StageCode;
}
