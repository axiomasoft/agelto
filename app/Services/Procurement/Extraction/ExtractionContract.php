<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\PromptSnapshot;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\RowRange;
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

        return new StructuredTask(ExtractionOutput::class, new PromptSnapshot($content, 1), TaskPurpose::Rows,
            new ExtractionSemanticValidator($range, $estimateOrdinal, $anchors));
    }
}
