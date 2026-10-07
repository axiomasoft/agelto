<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Events\Procurement\SourceIncidentRecorded;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Repositories\Procurement\Crawler\SourceIncidentRepository;

/**
 * Публикация события источника: строка журнала `prc_source_incidents` (видна в админке) и
 * доменное событие `SourceIncidentRecorded` (точка подключения внешних уведомлений). Логов не
 * пишет — это `SourceEventLogger`; вызывается только через `SourceEventReporter`.
 */
final readonly class SourceIncidentPublisher
{
    public function __construct(private SourceIncidentRepository $incidents) {}

    /** @param array<string, mixed> $attributes поля строки журнала, кроме кода и важности */
    public function publish(CrawlerEventCode $code, string $message, array $attributes): SourceIncident
    {
        $incident = $this->incidents->create(['type' => $code, 'severity' => $code->severity(), 'message' => $message] + $attributes);

        SourceIncidentRecorded::dispatch($incident->id, $incident->platform_id, $code, $code->severity(), $message);

        return $incident;
    }
}
