<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;

final readonly class AdmissionGroupSnapshot
{
    /** @param array<array-key, ProcessUuid> $laneIds */
    public function __construct(
        public ProcessUuid $id,
        public int $dispatchGeneration,
        public int $version,
        public ContentHash $requiredLaneSetHash,
        public array $laneIds,
        public ?ProcessUuid $attemptId,
    ) {
        ProcessValue::positive($dispatchGeneration);
        ProcessValue::positive($version);
        if ($laneIds === [] || ! array_is_list($laneIds)) {
            throw new \InvalidArgumentException('Admission snapshot requires its full lane membership.');
        }
        $ids = array_map(static fn (ProcessUuid $id): string => $id->value, $laneIds);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Duplicate admission member.');
        }
    }
}
