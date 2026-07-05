<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Dto\Crawler\ListingBatch;
use App\Dto\Crawler\ListingCursor;
use App\Dto\Crawler\ProcurementDetails;
use App\Models\Crawler\Crawler;
use App\Models\Platform\Registry;
use App\Services\Crawler\Contracts\SourceMethod;

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
