<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Projection;

use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Governance\WorkStateCount;
use App\Dto\Procurement\Process\Primitives\DbInstant;
use App\Enums\Procurement\Process\Governance\ControlMode;

/** Shared CLI/Pulse read model. Watermarks and counters have distinct provenance. */
final readonly class PlatformMetricsSnapshot
{
    /**
     * @param  list<MetricTotalRow>  $counters  Active generation, global receipts deduplicate events.
     * @param  list<string>  $pausedScopes  Human drilldown; never metric labels.
     * @param  list<WorkStateCount>  $workCounts  Current authority, not event payloads.
     */
    public function __construct(
        public PlatformCode $platform,
        public DbInstant $measuredAt,
        public ?string $coveredThrough,
        public MetricMeasurement $acquisitionWatermarkLag,
        public MetricMeasurement $listingClosedCompleteRatio,
        public int $closedPartitions,
        public int $collectingPartitions,
        public MetricMeasurement $projectionLag,
        public ?ProjectionState $projection,
        public array $counters,
        public array $pausedScopes,
        public array $workCounts,
        public MetricMeasurement $detailDueLag,
        public MetricMeasurement $statusRefreshDueLag,
        public ?ControlMode $acquisitionControl,
    ) {}
}
