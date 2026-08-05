<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use Illuminate\Support\Carbon;

/**
 * Метрики одного забора блока (`CsvBlockIngestor::ingest()`) — счётчики для `prc_fetches`
 * и наблюдаемости прогона (новые/обновлённые/пропущенные закупки, ошибки одной строки,
 * строки, схлопнутые дедупом внутри блока — П-5). `oldestUpdateSeen` — минимум «Даты
 * обновления» среди разобранных снапшотов блока (П-4); носитель — `CrawlerRunManager`
 * (`recordOldestUpdateSeen()`), не эта DTO.
 */
final readonly class FetchResult
{
    public function __construct(
        public int $rowsTotal,
        public int $rowsNew,
        public int $rowsUpdated,
        public int $rowsSkipped,
        public int $errors,
        public int $rowsDuplicated = 0,
        public ?Carbon $oldestUpdateSeen = null,
    ) {}
}
