<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceEvent;
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

    public function publish(SourceEvent $event): SourceIncident
    {
        $incident = $this->incidents->create($event);

        SourceIncidentRecorded::dispatch($incident->id, $incident->platform_id, $event->code, $event->code->severity(), $event->message);

        return $incident;
    }
}
