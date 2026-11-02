<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

/** The declared source range is independent of whatever the model happens to return. */
final readonly class AnalysisWindowPlan
{
    /** @param list<AnalysisWindowSnapshot> $windows */
    public function __construct(public AnalysisRunSnapshot $run, public int $estimateOrdinal, public RowRange|PageRange $required, public array $windows)
    {
        if ($windows === [] || count($windows) > 4096 || $estimateOrdinal < 1) {
            throw new \InvalidArgumentException('Invalid bounded window plan.');
        }
        $cursor = $required->start;
        $keys = [];
        $anchors = [];
        $previous = null;
        foreach ($windows as $window) {
            if ($window->run != $run || $required::class !== $window->range::class || $window->estimateOrdinal !== $estimateOrdinal || isset($keys[$window->windowKey])
                || $window->range->start < $required->start || $window->range->end > $required->end
                || $window->range->start > $cursor || $window->range->end <= $cursor
                || $window->range->end - $window->range->start > $run->recipe->coverage->windowSpan
                || ($previous === null && $window->range->start !== $required->start)
                || ($previous !== null && $previous->range->end - $window->range->start !== $run->recipe->coverage->overlap)) {
                throw new \InvalidArgumentException('Window plan has a gap, overlap drift, or foreign identity.');
            }
            foreach ($window->sourceAnchors as $anchor => $page) {
                if (isset($anchors[$anchor]) && $anchors[$anchor] !== $page) {
                    throw new \InvalidArgumentException('Window plan has conflicting source anchors.');
                }
                $anchors[$anchor] = $page;
            }
            $keys[$window->windowKey] = true;
            $cursor = $window->range->end;
            $previous = $window;
        }
        if ($cursor !== $required->end) {
            throw new \InvalidArgumentException('Window plan omits required source coverage.');
        }
    }
}
