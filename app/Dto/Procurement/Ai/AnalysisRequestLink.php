<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;

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
