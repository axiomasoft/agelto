<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

use Illuminate\Support\Carbon;

/**
 * Одна строка журнала событий вкладки `event-journal` карточки закупки ЕИС
 * (`EventJournalParser::parse()`). Дата уже разобрана, текст события — сырой, без
 * маппинга в `ProcurementEventType` (это `EventJournalProcessor`, дополняющий
 * best-effort источник — `01-database.md` §3).
 */
final readonly class JournalEntryData
{
    public function __construct(
        public Carbon $occurredAt,
        public string $rawText,
    ) {}
}
