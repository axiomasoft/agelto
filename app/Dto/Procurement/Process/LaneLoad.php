<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/**
 * Unlocked load одной lane для выбора feeder: limits, outstanding по active reservation rows и
 * fairness cursor. Только pre-read: authoritative W/C/cursor сверяются под locks admit tx.
 */
final readonly class LaneLoad
{
    public function __construct(
        public ProcessUuid $id,
        public string $key,
        public int $maxOutstanding,
        public int $lowWatermark,
        public int $refillMax,
        public int $outstanding,
        public FairnessCursor $cursor,
    ) {
        ProcessValue::nonNegative($outstanding);
    }
}
