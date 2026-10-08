<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Crawler\SourceEvent;
use App\Models\Procurement\Crawler\SourceIncident;

/** Запись журнала событий источника (`prc_source_incidents`). */
final readonly class SourceIncidentRepository
{
    public function create(SourceEvent $event): SourceIncident
    {
        return SourceIncident::create([
            'type' => $event->code,
            'severity' => $event->code->severity(),
            'message' => $event->message,
            'platform_id' => $event->platformId,
            'crawler_id' => $event->crawlerId,
            'run_id' => $event->runId,
            'fetch_id' => $event->fetchId,
            'failure_kind' => $event->failureKind,
            'http_status' => $event->httpStatus,
            'cursor' => $event->cursor,
            'request_params' => $event->requestParams,
            'context' => $event->incidentContext(),
            'actor' => $event->actor,
            'created_at' => now(),
        ]);
    }
}
