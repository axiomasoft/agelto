<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessValue;

/**
 * Versioned limits одной capacity lane: W = maxOutstanding (admitted+running), C = maxRunning.
 * Те же неравенства, что CHECK `prc_process_lanes`; это стартовая конфигурация, не семантика stage.
 */
final readonly class LanePolicy
{
    public function __construct(
        public string $key,
        public ?string $parentKey,
        public int $maxOutstanding,
        public int $maxRunning,
        public int $lowWatermark,
        public int $refillMax,
    ) {
        ProcessValue::text($key);
        if ($parentKey !== null) {
            ProcessValue::text($parentKey);
        }
        if ($maxOutstanding < 1 || $maxRunning < 1 || $maxRunning > $maxOutstanding
            || $lowWatermark < 0 || $lowWatermark >= $maxOutstanding || $refillMax < 1 || $refillMax > $maxOutstanding) {
            throw new \InvalidArgumentException('Lane limits must satisfy 0 < C <= W, 0 <= low < W and 0 < refill <= W: '.$key);
        }
    }
}
