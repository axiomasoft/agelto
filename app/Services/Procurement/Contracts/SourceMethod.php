<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;

/**
 * Метод забора данных с площадки — переключаемый параметр профиля (`02-crawling.md` §1–§2),
 * не ветка кода. Реализации: `CsvSourceMethod` (основной), `HtmlSourceMethod`/`ApiSourceMethod`
 * (задел, вне V2-CRAWL). Всё платформо-специфичное — в адаптере площадки (`Eis\...`).
 */
interface SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch;

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails;

    public function isAvailable(): bool;
}
