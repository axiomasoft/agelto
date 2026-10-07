<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceEvent;
use App\Models\Procurement\Crawler\SourceIncident;

/**
 * Единственный путь любого события источника: `report()` — журнал + лог с одними тегами,
 * `log()` — только лог (реакция `LogOnly`). Вызывать вне транзакции обрабатываемого
 * блока/карточки, чтобы запись пережила её откат.
 */
final readonly class SourceEventReporter
{
    public function __construct(
        private SourceIncidentPublisher $publisher,
        private SourceEventLogger $logger,
    ) {}

    public function report(SourceEvent $event): SourceIncident
    {
        $incident = $this->publisher->publish($event);
        $this->logger->log($event, $incident->id);

        return $incident;
    }

    public function log(SourceEvent $event): void
    {
        $this->logger->log($event);
    }
}
