<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Enums\Procurement\Process\Core\StageCode;

interface WorkInput
{
    public function stage(): StageCode;
}
