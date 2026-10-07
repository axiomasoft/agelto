<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use Illuminate\Support\Carbon;

/**
 * Метрики одного забора блока (`CsvBlockIngestor::ingest()`) — счётчики для `prc_fetches`
 * и наблюдаемости прогона (принятые/новые/обновлённые/пропущенные закупки, ошибки одной
 * строки, строки, схлопнутые дедупом внутри блока — П-5). `oldestUpdateSeen` — минимум «Даты
 * обновления» среди разобранных снапшотов блока (П-4); носитель — `CrawlerRunManager`
 * (`recordOldestUpdateSeen()`), не эта DTO.
 *
 * `failure` — класс отказа блока (`null` — блок прочитан чисто). `sourceSuspended` — отказ
 * открыл брейкер источника: курсор двигать НЕЛЬЗЯ, прогон уходит в паузу до пробы.
 */
final readonly class FetchResult
{
    public function __construct(
        public int $rowsTotal,
        public int $rowsParsed,
        public int $rowsNew,
        public int $rowsUpdated,
        public int $rowsSkipped,
        public int $rowsErrors,
        public int $rowsDuplicated = 0,
        public ?Carbon $oldestUpdateSeen = null,
        public bool $oldestUpdateSeenHasDayPrecision = false,
        public ?ListingFailureKind $failure = null,
        public bool $sourceSuspended = false,
    ) {}

    /** Блок не прочитан и пропускается: курсор переходит через него, пропуск записан инцидентом. */
    public function blockSkipped(): bool
    {
        return ! $this->sourceSuspended && $this->failure !== null && $this->failure->skipsBlock();
    }
}
