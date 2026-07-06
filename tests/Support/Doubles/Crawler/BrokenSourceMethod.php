<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;
use RuntimeException;

/**
 * `SourceMethod`-double, который сразу падает при попытке собрать листинг —
 * имитирует профиль, чей прогон должен упасть, не прерывая обработку остальных
 * профилей в `RunFreshCrawler` (см. `RunFreshCrawlerTest`).
 */
final class BrokenSourceMethod implements SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        throw new RuntimeException('BrokenSourceMethod: смоделированный сбой сбора листинга.');
    }

    public function collectDetails(string $sourceNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
