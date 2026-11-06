<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\WorkId;

/** Current source pins and work ancestry checked under the manual reanalysis subject locks. */
final readonly class ReanalysisSource
{
    public function __construct(
        public int $documentHeadGeneration,
        public int $nextAnalysisGeneration,
        public WorkId $previousScreeningWorkId,
        public int $platformId,
        public string $cohortKey,
    ) {}
}
