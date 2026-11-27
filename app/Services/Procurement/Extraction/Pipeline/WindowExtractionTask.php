<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Pipeline;

use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\Output\ExtractionOutput;
use App\Dto\Procurement\Ai\Output\PageExtractionOutput;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Enums\Llm\TaskPurpose;

final class WindowExtractionTask
{
    /** @return StructuredTask<ExtractionOutput|PageExtractionOutput> */
    public static function for(AnalysisWindowSnapshot $window, RowRange|PageRange $range): StructuredTask
    {
        if (($window->range instanceof PageRange) !== ($range instanceof PageRange)) {
            throw new \InvalidArgumentException('Range unit drift.');
        }
        $snapshot = $window->run->recipe->tasks->task(TaskPurpose::Rows);

        return new StructuredTask($range instanceof PageRange ? PageExtractionOutput::class : ExtractionOutput::class,
            $snapshot->prompt, TaskPurpose::Rows,
            new ExtractionSemanticValidator(expected: $range, estimateOrdinal: $window->estimateOrdinal, sourceAnchors: $window->sourceAnchors,
                blankPages: $window->run->source->blankPages ?? [], checkPositionTotals: false, arithmetic: $window->run->recipe->arithmetic));
    }
}
