<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Execution;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;

/** Exact persisted linkage; expectedWorkInputHash fingerprints Process input, not a provider subrange. */
final readonly class AnalysisRequestLink
{
    public function __construct(
        public ProcessUuid $analysisRunId,
        public WorkId $workId,
        public string $logicalRequestId,
        public string $physicalCallId,
        public ContentHash $expectedWorkInputHash,
        public ContentHash $recipeHash,
    ) {}
}
