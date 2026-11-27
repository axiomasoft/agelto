<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Pipeline;

use App\Dto\Procurement\Ai\Extraction\ExtractionCheckpoint;
use App\Dto\Procurement\Ai\Planning\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Repositories\Procurement\Ai\ChunkRepository;

final class CoverageValidator
{
    public function __construct(private readonly ChunkRepository $chunks) {}

    /** Every accepted range and pending range must form exactly the immutable parent. */
    public function assertPartition(AnalysisWindowSnapshot $window, ExtractionCheckpoint $checkpoint): void
    {
        $accepted = $this->chunks->acceptedCoverage(window: $window, references: $checkpoint->acceptedChunks);
        $ranges = [...$checkpoint->pending, ...$accepted['ranges']];
        usort($ranges, static fn (RowRange|PageRange $a, RowRange|PageRange $b): int => $a->start <=> $b->start);
        $cursor = $window->range->start;
        foreach ($ranges as $range) {
            if ($range::class !== $window->range::class || $range->start !== $cursor || $range->end > $window->range->end) {
                throw new \InvalidArgumentException('Accepted and pending coverage overlap or leave a gap.');
            }
            $cursor = $range->end;
        }
        if ($cursor !== $window->range->end) {
            throw new \InvalidArgumentException('Incomplete parent coverage.');
        }
        if ($accepted['storedChunkCount'] !== count($accepted['ranges'])) {
            throw new \InvalidArgumentException('Checkpoint dropped an accepted chunk.');
        }
    }
}
