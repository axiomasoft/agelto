<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisWindowPlan;
use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\PageExtractionOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Ai\StitchedEstimate;
use App\Dto\Procurement\Ai\ValidatedChunk;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Services\Llm\Schema\CanonicalJson;

/** Deterministic merge: matching overlap is evidence; differing overlap is a conflict. */
final class ChunkStitcher
{
    /** @param list<ValidatedChunk> $chunks */
    public function stitch(AnalysisWindowPlan $plan, array $chunks): StitchedEstimate
    {
        if ($chunks === [] || count($chunks) > 4096) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }
        $windows = [];
        foreach ($plan->windows as $window) {
            $windows[$window->id->value] = $window;
        }
        $ranges = $seen = $indices = $rows = $totals = $rowChunks = $totalChunks = $anchors = [];
        foreach ($chunks as $chunk) {
            if (($windows[$chunk->window->id->value] ?? null) != $chunk->window || $chunk->outputOrdinal !== 0 || isset($seen[$chunk->id->value])) {
                throw new PublicationBlocked(BlockedReason::ChunkConflict);
            }
            $seen[$chunk->id->value] = true;
            $ranges[$chunk->window->id->value][] = $chunk->range;
            $anchors += $chunk->window->sourceAnchors;
            $output = ($plan->required instanceof PageRange ? PageExtractionOutput::class : ExtractionOutput::class)::from(json_decode($chunk->canonicalRows, true, flags: JSON_THROW_ON_ERROR));
            foreach ($output->rows as $row) {
                $anchor = $row->source_anchor;
                if ((isset($rows[$anchor]) && CanonicalJson::encode($rows[$anchor]->toArray()) !== CanonicalJson::encode($row->toArray()))
                    || (isset($indices[$row->row->row_index]) && $indices[$row->row->row_index] !== $anchor)) {
                    throw new PublicationBlocked(BlockedReason::ChunkConflict);
                }
                $indices[$row->row->row_index] = $anchor;
                $rows[$anchor] = $row;
                $rowChunks[$anchor][] = $chunk->id;
            }
            foreach ($output->totals as $node) {
                if (isset($totals[$node->node_id]) && CanonicalJson::encode($totals[$node->node_id]->toArray()) !== CanonicalJson::encode($node->toArray())) {
                    throw new PublicationBlocked(BlockedReason::ChunkConflict);
                }
                $totals[$node->node_id] = $node;
                $totalChunks[$node->node_id][] = $chunk->id;
            }
        }
        foreach ($windows as $id => $window) {
            $parts = $ranges[$id] ?? [];
            usort($parts, static fn (RowRange|PageRange $a, RowRange|PageRange $b): int => $a->start <=> $b->start);
            $cursor = $window->range->start;
            foreach ($parts as $part) {
                if ($part->start !== $cursor || $part->end > $window->range->end) {
                    throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
                }
                $cursor = $part->end;
            }
            if ($cursor !== $window->range->end) {
                throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
            }
        }
        $ordered = array_values($rows);
        usort($ordered, static fn ($a, $b): int => $a->row->row_index <=> $b->row->row_index);
        $nodes = array_values($totals);
        $errors = (new ExtractionSemanticValidator($plan->required, $plan->estimateOrdinal, $anchors, $plan->run->source->blankPages ?? []))->errors(
            $plan->required instanceof PageRange ? new PageExtractionOutput($ordered, $nodes, range($plan->required->start, $plan->required->end - 1)) : new ExtractionOutput($ordered, $nodes));
        if ($errors !== []) {
            throw new PublicationBlocked(BlockedReason::SemanticReviewRequired);
        }
        if ($ordered === []) {
            throw new PublicationBlocked(BlockedReason::ExtractionIncomplete);
        }

        return new StitchedEstimate($plan, $ordered, $nodes, $rowChunks, $totalChunks);
    }
}
