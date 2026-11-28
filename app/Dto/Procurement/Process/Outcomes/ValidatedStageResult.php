<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

use App\Enums\Procurement\Process\Core\StageCode;

interface ValidatedStageResult
{
    public function stage(): StageCode;
}
