<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\FlatTotalNode;
use App\Dto\Procurement\Ai\MoneyDecimal;
use App\Dto\Procurement\Ai\PageExtractionOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\QuantityDecimal;
use App\Dto\Procurement\Ai\RowRange;
use App\Services\Llm\Validation\SemanticValidator;
use Spatie\LaravelData\Data;

/** @implements SemanticValidator<ExtractionOutput|PageExtractionOutput> */
final readonly class ExtractionSemanticValidator implements SemanticValidator
{
    /**
     * @param  array<string,int>  $sourceAnchors  Immutable representation anchor => original page.
     * @param  list<int>  $blankPages
     */
    public function __construct(private RowRange|PageRange $expected, private int $estimateOrdinal, private array $sourceAnchors,
        private array $blankPages = []) {}

    /** @return list<string> */
    public function errors(Data $data): array
    {
        $pageRange = $this->expected instanceof PageRange;
        $expectedPages = $pageRange ? range($this->expected->start, $this->expected->end - 1) : [];
        if ($pageRange && (! $data instanceof PageExtractionOutput || $data->covered_pages !== $expectedPages)) {
            return ['coverage_gap'];
        }
        if ($data->rows === [] && (! $pageRange || array_diff($expectedPages, $this->blankPages) !== [])) {
            return ['empty_required_output'];
        }
        $errors = [];
        $indices = [];
        $anchors = [];
        foreach ($data->rows as $anchored) {
            $row = $anchored->row;
            if ($anchored->estimate_ordinal !== $this->estimateOrdinal
                || ($this->sourceAnchors[$anchored->source_anchor] ?? null) !== $anchored->page_index) {
                $errors[] = 'invalid_source_anchor';
            }
            if (isset($indices[$row->row_index]) || isset($anchors[$anchored->source_anchor])) {
                $errors[] = 'duplicate_row';
            }
            $indices[$row->row_index] = true;
            $anchors[$anchored->source_anchor] = true;
            if (! $pageRange && ($row->row_index < $this->expected->start || $row->row_index >= $this->expected->end)) {
                $errors[] = 'row_out_of_range';
            }
            if ($pageRange && ($anchored->page_index < $this->expected->start || $anchored->page_index >= $this->expected->end
                || preg_match('/^page:([0-9]+):line:([0-9]+)$/D', $anchored->source_anchor, $position) !== 1
                || $row->row_index !== (int) $position[1] * 100000 + (int) $position[2])) {
                $errors[] = 'row_out_of_range';
            }
            if ($row->quantity !== null) {
                $quantity = new QuantityDecimal($row->quantity);
            }
            foreach ([[$row->unit_price_base, $row->total_price_base], [$row->unit_price_curr, $row->total_price_curr]] as [$price, $total]) {
                if ($price !== null && $total !== null && $row->quantity !== null) {
                    $unitPrice = new MoneyDecimal($price);
                    $rowTotal = new MoneyDecimal($total);
                    $quantity = new QuantityDecimal($row->quantity);
                    if (bccomp(bcmul($quantity->numeric(), $unitPrice->numeric(), 16), $rowTotal->numeric(), 2) !== 0) {
                        $errors[] = 'totals_arithmetic';
                    }
                }
            }
            if ($row->quantity !== null && ($row->unit === null || trim($row->unit) === '')) {
                $errors[] = 'missing_unit';
            }
            if ($row->quality !== null && (! $row->quality->reason->isModelOrigin() || $row->quality->fields === [])) {
                $errors[] = 'invalid_model_quality';
            }
        }
        if (! $pageRange && count($indices) !== $this->expected->end - $this->expected->start) {
            $errors[] = 'coverage_gap';
        }
        $errors = [...$errors, ...$this->totalsErrors($data->totals)];

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<FlatTotalNode>  $nodes
     * @return list<string>
     */
    private function totalsErrors(array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            if ($node->estimate_ordinal !== $this->estimateOrdinal || ! array_key_exists($node->source_anchor, $this->sourceAnchors)) {
                return ['invalid_total_anchor'];
            }
            if (isset($byId[$node->node_id])) {
                return ['duplicate_total_node'];
            }
            $byId[$node->node_id] = $node;
        }
        $resolved = [];
        $steps = 0;
        foreach ($nodes as $node) {
            $seen = [];
            $cursor = $node;
            while (true) {
                if (++$steps > 8192) {
                    return ['totals_traversal_limit'];
                }
                if (isset($resolved[$cursor->node_id])) {
                    break;
                }
                if (isset($seen[$cursor->node_id])) {
                    return ['total_cycle'];
                }
                $seen[$cursor->node_id] = true;
                if ($cursor->parent_id === null) {
                    break;
                }
                if (! isset($byId[$cursor->parent_id])) {
                    return ['missing_total_parent'];
                }
                $cursor = $byId[$cursor->parent_id];
            }
            foreach (array_keys($seen) as $id) {
                $resolved[$id] = true;
            }
        }

        return [];
    }
}
