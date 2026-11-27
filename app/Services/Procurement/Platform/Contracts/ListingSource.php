<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionContext;

interface ListingSource
{
    public function fetch(ListingRequest $request, ExecutionContext|OutboundExecutionContext $context): ListingFetchResult;
}
