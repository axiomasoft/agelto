<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionContext;

/** Status uses the canonical snapshot assessment and the same closed dependency outcomes. */
interface StatusSource
{
    public function refresh(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult;
}
