<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceEvent;
use Illuminate\Support\Facades\Log;

/**
 * Структурный лог событий краулера: уровень из кода, сообщение `"<КОД>: <текст>"`, теги
 * `SourceEvent::tags()`. Пишет в штатный канал Laravel (`LOG_CHANNEL`; в проде — `json`).
 */
final readonly class SourceEventLogger
{
    public function log(SourceEvent $event, ?int $incidentId = null): void
    {
        Log::log($event->code->severity()->logLevel(), "{$event->code->value}: {$event->message}", $event->tags($incidentId));
    }
}
