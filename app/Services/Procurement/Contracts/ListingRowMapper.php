<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Dto\Procurement\Crawler\SkippedListingRow;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Procurement\Crawler\Crawler;

/**
 * Строка сырого listing batch преобразуется в ProcurementSnapshotData. Площадочный
 * адаптер знает колонки и форматы дат; общий контракт и CsvBlockIngestor от площадки не зависят.
 */
interface ListingRowMapper
{
    /**
     * @param  array<string, string>  $row
     *                                      Null is malformed data and prevents cursor advancement; SkippedListingRow is an intentional exclusion.
     */
    public function map(array $row, Crawler $crawler): ProcurementSnapshotData|SkippedListingRow|null;
}
