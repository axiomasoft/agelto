<?php

declare(strict_types=1);

namespace App\Events\Procurement;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceIncidentSeverity;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Доменное событие контура управления источником: записан инцидент (пропуск блока, пауза или
 * возобновление источника, действие оператора). Отправляется после коммита транзакции.
 * Сейчас его читают журнал в админке и лог; внешняя доставка (Telegram/почта) — будущий
 * listener, без изменения источников события.
 */
final readonly class SourceIncidentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $incidentId,
        public int $platformId,
        public CrawlerEventCode $type,
        public SourceIncidentSeverity $severity,
        public string $message,
    ) {}
}
