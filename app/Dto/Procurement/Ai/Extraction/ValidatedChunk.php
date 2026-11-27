<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Extraction;

use App\Dto\Llm\Requests\StructuredResult;
use App\Dto\Procurement\Ai\Output\ExtractionOutput;
use App\Dto\Procurement\Ai\Output\PageExtractionOutput;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\Planning\ChunkKey;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\Pipeline\WindowExtractionTask;

final readonly class ValidatedChunk
{
    public ContentHash $subrangeKey;

    public ContentHash $outputHash;

    public string $canonicalRows;

    public ChunkKey $key;

    /** @param StructuredResult<ExtractionOutput|PageExtractionOutput> $result */
    public function __construct(
        public ProcessUuid $id,
        public AnalysisWindowSnapshot $window,
        public RowRange|PageRange $range,
        public int $outputOrdinal,
        public ArtifactRef $outputRef,
        StructuredResult $result,
    ) {
        if ($result->disposition !== StructuredDisposition::Accepted || ! ($result->data instanceof ExtractionOutput || $result->data instanceof PageExtractionOutput) || $outputOrdinal < 0) {
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
        $snapshot = $window->run->recipe->tasks->task(TaskPurpose::Rows);
        $task = WindowExtractionTask::for($window, $range);
        if ($task->schema->hash !== $snapshot->schema->hash
            || (new StructuredTaskValidator(new CanonicalSchemaValidator))->validate($task, $this->canonicalRows, 'STOP')->disposition !== StructuredDisposition::Accepted) {
            throw new \InvalidArgumentException('Chunk contract/range/recipe drift.');
        }
    }

}
