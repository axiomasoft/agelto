<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Pipeline;

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\Output\ExtractionOutput;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Enums\Llm\TaskPurpose;

/** Staged contract factory; not registered as a live stage handler. */
final class ExtractionContract
{
    /**
     * @param  array<string, int>  $anchors
     * @return StructuredTask<ExtractionOutput>
     */
    public function task(RowRange $range, int $estimateOrdinal, array $anchors): StructuredTask
    {
        $content = file_get_contents(resource_path('ai/prompts/contracts/extraction-v1.txt'));
        if ($content === false) {
            throw new \LogicException('Missing pinned extraction prompt.');
        }

        /** @var StructuredTask<ExtractionOutput> $task */
        $task = new StructuredTask(ExtractionOutput::class, new PromptSnapshot($content, 1), TaskPurpose::Rows,
            new ExtractionSemanticValidator(expected: $range, estimateOrdinal: $estimateOrdinal, sourceAnchors: $anchors,
                arithmetic: ExtractionSemanticValidator::configuredArithmetic()));

        return $task;
    }
}
