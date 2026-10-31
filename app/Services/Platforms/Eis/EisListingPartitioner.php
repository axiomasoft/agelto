<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Procurement\Acquisition\PartitionResult;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Services\Procurement\Platform\Contracts\ListingPartitioner;

/** Pure plan, never schedules, writes or finalizes children. */
final readonly class EisListingPartitioner implements ListingPartitioner
{
    public function __construct(private EisListingPolicy $policy = new EisListingPolicy) {}

    public function split(CursorEnvelope $cursor, int $allocatedPartitions): PartitionResult
    {
        $partition = EisListingCursor::decode($cursor);
        if ($allocatedPartitions < 1) {
            throw new \InvalidArgumentException('Invalid allocated partition count.');
        }
        $width = $partition->highCents - $partition->lowCents;
        if ($width <= $this->policy->minIntervalCents || $partition->depth >= $this->policy->maxDepth || $allocatedPartitions + 2 > $this->policy->maxPartitions) {
            return new PartitionResult([], AssessmentReason::Overflow);
        }
        $mid = $partition->lowCents + intdiv($width, 2);

        return new PartitionResult(array_map(static fn (array $range): CursorEnvelope => (new EisListingCursor(
            $partition->day, $partition->dateField, $range[0], $range[1], $partition->filterHash, 1, $partition->depth + 1, $partition->blockSize,
        ))->envelope(), [[$partition->lowCents, $mid], [$mid, $partition->highCents]]));
    }
}
