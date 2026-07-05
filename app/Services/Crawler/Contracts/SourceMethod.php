<?php

declare(strict_types=1);

namespace App\Services\Crawler\Contracts;

use App\Dto\Crawler\ListingBatch;
use App\Dto\Crawler\ListingCursor;
use App\Dto\Crawler\ProcurementDetails;
use App\Models\Crawler\Crawler;
use App\Models\Platform\Registry;

/**
 * Метод забора данных с площадки — переключаемый параметр профиля (`02-crawling.md` §1–§2),
 * не ветка кода. Реализации: `CsvSourceMethod` (основной), `HtmlSourceMethod`/`ApiSourceMethod`
 * (задел, вне V2-CRAWL). Всё платформо-специфичное — в адаптере площадки (`Eis\...`).
 */
interface SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch;

    public function collectDetails(string $sourceNumber, Registry $registry): ProcurementDetails;

    public function isAvailable(): bool;
}
