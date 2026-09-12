<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Dto\Procurement\ProcurementDetails;
use App\Models\Procurement\Platform\Registry;

interface DeadlineAwareSourceMethod extends SourceMethod
{
    public function collectDetailsWithinDeadline(string $registryNumber, Registry $registry, float $deadlineAt): ProcurementDetails;
}
