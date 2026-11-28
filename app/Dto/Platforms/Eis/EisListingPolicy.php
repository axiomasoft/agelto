<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

/** Local resource bounds, never a claim about a source cap or source SLA. */
final readonly class EisListingPolicy
{
    public function __construct(
        public int $maxBlocks = 12,
        public int $minIntervalCents = 100000,
        public int $maxDepth = 20,
        public int $maxPartitions = 10000,
        public int $parserSeconds = 5,
    ) {
        if ($maxBlocks < 1 || $maxBlocks > 12 || $minIntervalCents < 1 || $maxDepth < 1 || $maxDepth > 20
            || $maxPartitions < 2 || $maxPartitions > 10000 || $parserSeconds < 1 || $parserSeconds > 30) {
            throw new \InvalidArgumentException('Invalid EIS local resource bounds.');
        }
    }
}
