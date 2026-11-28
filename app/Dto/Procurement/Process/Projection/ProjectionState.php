<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Projection\ProjectionMode;

/** Строка `prc_process_projection_states`: gate, active generation и признак полноты истории. */
final readonly class ProjectionState
{
    public function __construct(
        public string $projectorKey,
        public ProjectionMode $mode,
        public int $activeGeneration,
        public ContentHash $catalogHash,
        public bool $historyComplete,
        public ?ProcessUuid $maintenanceToken,
    ) {
        ProcessValue::text($projectorKey);
        ProcessValue::positive($activeGeneration);
        if (($mode === ProjectionMode::Maintenance) !== ($maintenanceToken !== null)) {
            throw new \InvalidArgumentException('Maintenance mode and token must agree.');
        }
    }
}
