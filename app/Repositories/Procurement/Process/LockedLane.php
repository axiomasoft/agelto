<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

/** Capacity lane под FOR UPDATE и authoritative COUNT её active reservation rows после lock. */
final readonly class LockedLane
{
    public function __construct(
        public string $id,
        public string $key,
        public ?string $parentId,
        public int $maxOutstanding,
        public int $maxRunning,
        public int $outstanding,
        public int $running,
        public string $fairnessCursor,
        public int $fairnessCursorSchemaVersion,
    ) {}
}
