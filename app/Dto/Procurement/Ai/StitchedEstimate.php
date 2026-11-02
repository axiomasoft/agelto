<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ProcessUuid;

/** Each normalized row/total keeps every accepted chunk that attests its source anchor. */
final readonly class StitchedEstimate
{
    /**
     * @param  non-empty-list<AnchoredEstimateRow>  $rows
     * @param  list<FlatTotalNode>  $totals
     * @param  array<string,non-empty-list<ProcessUuid>>  $rowChunks
     * @param  array<string,non-empty-list<ProcessUuid>>  $totalChunks
     */
    public function __construct(public AnalysisWindowPlan $plan, public array $rows, public array $totals,
        public array $rowChunks, public array $totalChunks) {}
}
