<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Projection;

/** State and active counters from one database snapshot, including during rebuild. */
final readonly class ProjectionTotals
{
    /** @param list<MetricTotalRow> $totals */
    public function __construct(public ?ProjectionState $state, public array $totals) {}
}
