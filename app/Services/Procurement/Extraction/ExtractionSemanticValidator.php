<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnchoredEstimateRow;
use App\Dto\Procurement\Ai\ExtractionArithmeticPolicy;
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
        private array $blankPages = [], private bool $checkPositionTotals = true, private ?ExtractionArithmeticPolicy $arithmetic = null) {}

    public static function configuredArithmetic(): ExtractionArithmeticPolicy
    {
        return new ExtractionArithmeticPolicy(
            roundingTolerance: config()->string('ai.extraction.rounding_tolerance'),
            coefficientTolerance: config()->string('ai.extraction.coefficient_tolerance'),
        );
    }

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
        $rounding = $this->arithmetic->roundingTolerance ?? '0.00';
        $coefficient = $this->arithmetic?->coefficientTolerance;
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
            if (! $this->rowAmountsMatch(anchored: $anchored, rounding: $rounding, coefficient: $coefficient)) {
                $errors[] = 'totals_arithmetic';
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
        if ($this->arithmetic !== null && $this->checkPositionTotals
            && ! $this->positionTotalMatches(rows: $data->rows, totals: $data->totals, tolerance: $rounding)) {
            $errors[] = 'totals_arithmetic';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  numeric-string  $rounding
     * @param  numeric-string|null  $coefficient
     */
    private function rowAmountsMatch(AnchoredEstimateRow $anchored, string $rounding, ?string $coefficient): bool
    {
        $row = $anchored->row;
        if ($row->quantity === null) {
            return true;
        }
        $quantity = (new QuantityDecimal($row->quantity))->numeric();
        $factors = [];
        foreach ($coefficient === null ? [] : ($row->attributes->modifiers ?? []) as $modifier) {
            if ($modifier->value !== null) {
                $factors[] = (new MoneyDecimal($modifier->value))->numeric();
            }
        }
        $indexed = $coefficient !== null && $row->index_value !== null && $row->total_price_base !== null
            ? $this->multiply(left: (new MoneyDecimal($row->total_price_base))->numeric(), right: (new MoneyDecimal($row->index_value))->numeric())
            : null;

        return $this->amountMatches(quantity: $quantity, price: $row->unit_price_base, total: $row->total_price_base, factors: $factors,
            indexed: null, rounding: $rounding, coefficient: $coefficient)
            && $this->amountMatches(quantity: $quantity, price: $row->unit_price_curr, total: $row->total_price_curr, factors: $factors,
                indexed: $indexed, rounding: $rounding, coefficient: $coefficient);
    }

    /**
     * @param  numeric-string  $quantity
     * @param  list<numeric-string>  $factors
     * @param  numeric-string|null  $indexed
     * @param  numeric-string  $rounding
     * @param  numeric-string|null  $coefficient
     */
    private function amountMatches(string $quantity, ?string $price, ?string $total, array $factors, ?string $indexed, string $rounding, ?string $coefficient): bool
    {
        if ($price === null || $total === null) {
            return true;
        }
        $actual = self::roundHalfUpCents((new MoneyDecimal($total))->numeric());
        $expected = $this->multiply(left: $quantity, right: (new MoneyDecimal($price))->numeric());
        if ($this->withinTolerance(expected: $expected, actual: $actual, tolerance: $rounding)) {
            return true;
        }
        if ($coefficient === null) {
            return false;
        }
        if ($indexed !== null && $this->withinTolerance(expected: $indexed, actual: $actual, tolerance: $coefficient)) {
            return true;
        }
        foreach ($factors as $factor) {
            $expected = bcmul($expected, $factor, 16);
        }

        return $factors !== [] && $this->withinTolerance(expected: $expected, actual: $actual, tolerance: $coefficient);
    }

    /**
     * @param  array<AnchoredEstimateRow>  $rows
     * @param  array<FlatTotalNode>  $totals
     * @param  numeric-string  $tolerance
     */
    private function positionTotalMatches(array $rows, array $totals, string $tolerance): bool
    {
        $sum = '0.00';
        $hasItems = false;
        foreach ($rows as $anchored) {
            if (! $anchored->isLineItem()) {
                continue;
            }
            $row = $anchored->row;
            if ($row->total_price_curr === null) {
                return true;
            }
            $hasItems = true;
            $sum = bcadd($sum, self::roundHalfUpCents((new MoneyDecimal($row->total_price_curr))->numeric()), 2);
        }
        if (! $hasItems) {
            return true;
        }

        // Only a root that explicitly sums line items is comparable: other totals may include taxes or uplifts.
        foreach ($totals as $node) {
            if ($node->parent_id === null && $node->value !== null
                && in_array(mb_strtolower(trim($node->label)), ['итого по позициям', 'сумма позиций'], true)
                && ! $this->withinTolerance(expected: $sum, actual: self::roundHalfUpCents((new MoneyDecimal($node->value))->numeric()), tolerance: $tolerance)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function multiply(string $left, string $right): string
    {
        return bcmul($left, $right, max(16, self::fractionLength($left) + self::fractionLength($right)));
    }

    /**
     * @param  numeric-string  $expected
     * @param  numeric-string  $actual
     * @param  numeric-string  $tolerance
     */
    private function withinTolerance(string $expected, string $actual, string $tolerance): bool
    {
        $difference = bcsub(self::roundHalfUpCents($expected), $actual, 2);

        $absoluteDifference = bccomp($difference, '0', 2) < 0 ? bcsub('0', $difference, 2) : $difference;

        return bccomp($absoluteDifference, $tolerance, 2) <= 0;
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

    private static function fractionLength(string $decimal): int
    {
        $dot = strpos($decimal, '.');

        return $dot === false ? 0 : strlen($decimal) - $dot - 1;
    }

    /**
     * @param  numeric-string  $decimal
     * @return numeric-string
     */
    private static function roundHalfUpCents(string $decimal): string
    {
        $offset = bccomp($decimal, '0', self::fractionLength($decimal)) < 0 ? '-0.005' : '0.005';

        return bcadd($decimal, $offset, 2);
    }
}
