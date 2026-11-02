<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;

final readonly class AnalysisRunSnapshot
{
    public function __construct(
        public ProcessUuid $id,
        public int $documentVersionId,
        public int $representationId,
        public ContentHash $representationHash,
        public AnalysisRecipe $recipe,
        public int $generation,
        public int $expectedDocumentHeadGeneration,
        public int $expectedAnalysisGeneration,
        public string $requestedReason,
        public ?ProcessUuid $supersedesRunId = null,
    ) {
        if (min($documentVersionId, $representationId, $generation, $expectedDocumentHeadGeneration, $expectedAnalysisGeneration) < 1 || $requestedReason === '') {
            throw new \InvalidArgumentException('Invalid analysis run identity.');
        }
    }
}
