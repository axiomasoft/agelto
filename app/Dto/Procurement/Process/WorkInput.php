<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

interface WorkInput
{
    public function stage(): StageCode;
}
