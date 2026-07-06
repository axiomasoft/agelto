<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;

abstract class FakeSourceMethod implements SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        return new ListingBatch(
            rows: [],
            rawBody: '',
            dedupSignature: null,
            rowsTotal: 0,
            httpStatus: 200,
            durationMs: 0,
            requestParams: [],
        );
    }

    public function collectDetails(string $sourceNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails;
    }
}
