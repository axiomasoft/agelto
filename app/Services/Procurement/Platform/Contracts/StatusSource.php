<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Process\ExecutionContext;

/** Status uses the canonical snapshot assessment and the same closed dependency outcomes. */
interface StatusSource
{
    public function refresh(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult;
}
