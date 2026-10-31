<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Services\Procurement\Platform\Contracts\ListingSource;

final readonly class JsonListingSource implements ListingSource
{
    public function __construct(private JsonProcurementSource $source) {}

    public function fetch(ListingRequest $request, ExecutionContext|OutboundExecutionContext $context): ListingFetchResult
    {
        return $this->source->fetchListing($request, $context);
    }
}
