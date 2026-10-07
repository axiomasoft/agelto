<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Models\Procurement\Crawler\SourceIncident;

/** Запись журнала событий источника (`prc_source_incidents`). */
final readonly class SourceIncidentRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): SourceIncident
    {
        return SourceIncident::create($attributes + ['created_at' => now()]);
    }
}
