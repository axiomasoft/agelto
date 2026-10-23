<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\ListingExecutionContext;
use App\Models\Procurement\Crawler\Crawler;

interface CooperativeListingSourceMethod extends SourceMethod
{
    public function collectListingForRun(Crawler $crawler, ListingCursor $cursor, ListingExecutionContext $context): ListingBatch;
}
