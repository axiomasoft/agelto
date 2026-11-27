<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Dto\Procurement\ProcurementSnapshotData;

/** Pure mapper output retained across validation and ingestion; persistence faults are separate. */
final readonly class ListingMapping
{
    /** @param list<ProcurementSnapshotData> $snapshots */
    public function __construct(public array $snapshots, public int $intentionalSkips = 0,
        public int $unmapped = 0, public int $errors = 0) {}
}
