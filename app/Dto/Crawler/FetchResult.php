<?php

declare(strict_types=1);

namespace App\Dto\Crawler;

/**
 * Метрики одного забора блока (`CsvBlockIngestor::ingest()`) — счётчики для `crw_fetches`
 * и наблюдаемости прогона (новые/обновлённые/пропущенные закупки, ошибки одной строки).
 */
final readonly class FetchResult
{
    public function __construct(
        public int $rowsTotal,
        public int $rowsNew,
        public int $rowsUpdated,
        public int $rowsSkipped,
        public int $errors,
    ) {}
}
