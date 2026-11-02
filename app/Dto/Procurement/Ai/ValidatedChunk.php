<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\ExtractionSemanticValidator;

final readonly class ValidatedChunk
{
    public ContentHash $subrangeKey;

    public ContentHash $outputHash;

    public string $canonicalRows;

    public ChunkKey $key;

    /** @param StructuredResult<ExtractionOutput> $result */
    public function __construct(
        public ProcessUuid $id,
        public AnalysisWindowSnapshot $window,
        public RowRange $range,
        public int $outputOrdinal,
        public ArtifactRef $outputRef,
        StructuredResult $result,
    ) {
        if ($result->disposition !== StructuredDisposition::Accepted || ! $result->data instanceof ExtractionOutput || $outputOrdinal < 0) {
            throw new \InvalidArgumentException('Only validated output can become a chunk.');
        }
        $this->subrangeKey = $window->subrangeKey($range);
        $this->key = new ChunkKey($window->run->id, $window->windowKey, $window->run->recipe->hash, $this->subrangeKey, $outputOrdinal);
        // Includes every flat total node and the original source anchors, with no depth truncation.
        $this->canonicalRows = CanonicalJson::encode($result->data->toArray());
        $this->outputHash = ContentHash::of($this->canonicalRows);
        if ($outputRef->sha256 != $this->outputHash) {
            throw new \InvalidArgumentException('Validated output artifact hash mismatch.');
        }
        $task = new StructuredTask(ExtractionOutput::class, $window->run->recipe->prompt, TaskPurpose::Rows,
            new ExtractionSemanticValidator($range, $window->estimateOrdinal, $window->sourceAnchors));
        if ($task->schema->hash !== $window->run->recipe->schemaHash->hex
            || (new StructuredTaskValidator(new CanonicalSchemaValidator))->validate($task, $this->canonicalRows, 'STOP')->disposition !== StructuredDisposition::Accepted) {
            throw new \InvalidArgumentException('Chunk contract/range/recipe drift.');
        }
    }

}
