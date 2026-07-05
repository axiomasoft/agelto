<?php

declare(strict_types=1);

namespace App\Services\Crawler\Contracts;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Crawler\Crawler;

/**
 * Строка сырого блока листинга (`ListingBatch->rows`) → нормализованный снапшот закупки.
 * Площадко-специфичен (имена колонок, формат дат) — реализация в `Eis\EisCsvRowMapper`,
 * контракт сам площадки не знает (`CsvBlockIngestor` — агностик, P2.5 `02-crawling.md` §3.4).
 */
interface ListingRowMapper
{
    /**
     * @param  array<string, string>  $row
     * @return ProcurementSnapshotData|null `null` — маппер решил пропустить строку (не закупка/битая строка), не ошибка.
     */
    public function map(array $row, Crawler $crawler): ?ProcurementSnapshotData;
}
