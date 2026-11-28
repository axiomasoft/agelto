<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Execution;

use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\PageSourceManifest;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Services\Llm\Schema\CanonicalJson;

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
        public ?PageSourceManifest $source = null,
    ) {
        if (min($documentVersionId, $representationId, $generation, $expectedDocumentHeadGeneration, $expectedAnalysisGeneration) < 1 || $requestedReason === '') {
            throw new \InvalidArgumentException('Invalid analysis run identity.');
        }
    }

    public function screeningInputHash(): ContentHash
    {
        return new ContentHash(CanonicalJson::hash(['version' => 1, 'task_purpose' => 'screening', 'run_id' => $this->id->value,
            'document_version_id' => $this->documentVersionId, 'representation_hash' => $this->representationHash->hex,
            'recipe_hash' => $this->recipe->hash->hex]));
    }

    public function discoveryInputHash(PageRange $range): ContentHash
    {
        if ($this->source === null || $range->end > $this->source->pageCount) {
            throw new \InvalidArgumentException('Independent source page manifest required.');
        }

        return new ContentHash(CanonicalJson::hash(['version' => 1, 'task_purpose' => 'discovery', 'run_id' => $this->id->value,
            'representation_hash' => $this->representationHash->hex, 'recipe_hash' => $this->recipe->hash->hex, 'range' => $range->fields()]));
    }
}
