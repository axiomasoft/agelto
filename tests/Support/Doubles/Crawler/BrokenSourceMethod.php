<?php

declare(strict_types=1);

namespace Tests\Support\Doubles\Crawler;

use App\Dto\Crawler\ListingBatch;
use App\Dto\Crawler\ListingCursor;
use App\Dto\Crawler\ProcurementDetails;
use App\Models\Crawler\Crawler;
use App\Models\Platform\Registry;
use App\Services\Crawler\Contracts\SourceMethod;
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
