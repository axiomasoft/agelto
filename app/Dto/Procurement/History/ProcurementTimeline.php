<?php

declare(strict_types=1);

namespace App\Dto\Procurement\History;

use Spatie\LaravelData\Data;

final class ProcurementTimeline extends Data
{
    /** @param list<TimelineEntry> $entries */
    public function __construct(
        public array $entries,
        public ProcurementDurations $durations,
    ) {}
}
