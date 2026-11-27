<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Execution;

use App\Dto\Procurement\Process\Primitives\ContentHash;

/** Prepared backlog snapshot; its document/version pins are revalidated before activation. */
final readonly class PreparedAnalysisCandidate
{
    public function __construct(
        public int $documentId,
        public int $documentVersionId,
        public int $representationId,
        public ContentHash $representationHash,
        public ContentHash $preparationHash,
        public int $documentHeadGeneration,
        public int $analysisGeneration,
        public ?int $platformId,
        public ?int $procurementId,
    ) {}
}
