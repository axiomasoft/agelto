<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\AnalysisWindowPlan;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;

/** Pure planning from sealed coverage policy and an independently declared source range. */
final class WindowPlanner
{
    /** @param array<string,int> $sourceAnchors */
    public function plan(AnalysisRunSnapshot $run, int $estimateOrdinal, RowRange|PageRange $required, array $sourceAnchors): AnalysisWindowPlan
    {
        $policy = $run->recipe->coverage;
        $windows = [];
        for ($start = $required->start; $start < $required->end;) {
            if (count($windows) >= 4096) {
                throw new \InvalidArgumentException('Window plan exceeds its bounded size.');
            }
            $end = min($required->end, $start + $policy->windowSpan);
            if ($required instanceof PageRange) {
                while ($end > $start + 1 && count(array_filter($sourceAnchors, static fn (int $page): bool => $page >= $start && $page < $end)) > 4096) {
                    $end--;
                }
                if (($end < $required->end && $end - $start <= $policy->overlap)
                    || count(array_filter($sourceAnchors, static fn (int $page): bool => $page >= $start && $page < $end)) > 4096) {
                    throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
                }
            }
            $key = 'estimate:'.$estimateOrdinal.':'.($required instanceof PageRange ? 'pages' : 'rows').':'.$start.':'.$end;
            $windows[] = new AnalysisWindowSnapshot(ListingStoreRepository::id('analysis-window:'.$run->id->value.':'.$key),
                $run, $key, ($required instanceof PageRange ? new PageRange($start, $end) : new RowRange($start, $end)), $estimateOrdinal,
                $required instanceof PageRange ? array_filter($sourceAnchors, static fn (int $page): bool => $page >= $start && $page < $end) : $sourceAnchors);
            if ($end === $required->end) {
                break;
            }
            $start = $end - $policy->overlap;
        }

        return new AnalysisWindowPlan($run, $estimateOrdinal, $required, $windows);
    }
}
