<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Process\ExecutionContext;

interface ListingSource
{
    public function fetch(ListingRequest $request, ExecutionContext $context): ListingFetchResult;
}
