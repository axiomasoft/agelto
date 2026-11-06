<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Estimate\RowQualityReason;

/**
 * Метрики офлайн-оценки качества извлечения (ТЗ 04 §5): field-level precision/recall/F1
 * на паре (row_index, field) + row coverage. Чистая функция над УЖЕ нормализованными массивами
 * `{"estimates": [{..PassportResponse-поля.., "rows": [EstimateRow-массивы]}]}`.
 */
final readonly class EvalMetrics
{
    private const int SCALE = 4;

    /** @var array<string, string> */
    public const array FIELD_MAP = [
        'row_type' => 'row_type',
        'code' => 'code',
        'name' => 'name',
        'unit' => 'unit',
        'quantity' => 'quantity',
        'price' => 'total_price_curr',
        'unit_price_base' => 'unit_price_base',
        'total_price_base' => 'total_price_base',
        'index_code' => 'index_code',
        'index_value' => 'index_value',
        'source_position_number' => 'source_position_number',
        'work_type_name' => 'work_type_name',
    ];

    /** @var list<string> */
    public const array ROW_NUMERIC_FIELDS = ['quantity', 'price', 'unit_price_base', 'total_price_base', 'index_value'];

    /** @var list<string> */
    public const array PASSPORT_FIELD_MAP = [
        'estimate_number',
        'currency',
        'base_year',
        'price_level',
        'calc_method',
        'labor_hours_workers',
        'labor_hours_machinists',
        'document_number',
        'document_date',
        'period_start',
        'period_end',
        'passport.type',
        'passport.reference',
        'passport.target',
        'passport.address',
        'passport.generator',
        'passport.contract_number',
        'passport.contract_date',
    ];

    /** @var list<string> */
    public const array PASSPORT_NUMERIC_FIELDS = ['labor_hours_workers', 'labor_hours_machinists'];

    /** @var list<string> */
    public const array TOTALS_FIELD_MAP = ['value', 'kind', 'chapter_number', 'rate'];

    /** @var list<string> */
    public const array TOTALS_NUMERIC_FIELDS = ['value', 'rate'];

    private const string QUALITY_ROW_TYPE = 'item';

    public function __construct(
        private EvalRowMatcher $matcher = new EvalRowMatcher,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     * @return array{
     *     per_field: array<string, array{precision: float, recall: float, f1: float}>,
     *     overall: array{precision: float, recall: float, f1: float},
     *     row_coverage: float,
     *     passport: array<string, array{precision: float, recall: float, f1: float}>,
     *     totals: array<string, array{precision: float, recall: float, f1: float}>,
     *     quality: array{marked_rows: int, item_rows: int, fill_rate: float, by_reason: array<string, int>},
     * }
     */
    public function compare(array $goldEstimates, array $predictedEstimates): array
    {
        $matches = $this->matchEstimates($goldEstimates, $predictedEstimates);

        $goldRows = $this->flattenRows($goldEstimates, $matches, forGold: true);
        $predictedRows = $this->flattenRows($predictedEstimates, $matches, forGold: false);

        $counts = $this->tallyFieldMap($goldRows, $predictedRows, self::FIELD_MAP, self::ROW_NUMERIC_FIELDS);
        $perField = array_map(fn (array $tally): array => $this->precisionRecallF1($tally['tp'], $tally['fp'], $tally['fn']), $counts);
        $totalTp = array_sum(array_column($counts, 'tp'));
        $totalFp = array_sum(array_column($counts, 'fp'));
        $totalFn = array_sum(array_column($counts, 'fn'));
        $matchedGoldRows = count(array_intersect_key($goldRows, $predictedRows));
        $rowCoverage = $goldRows === [] ? 1.0 : round($matchedGoldRows / count($goldRows), self::SCALE);

        return [
            'per_field' => $perField,
            'overall' => $this->precisionRecallF1($totalTp, $totalFp, $totalFn),
            'row_coverage' => $rowCoverage,
            'passport' => $this->aggregateFieldMetrics(
                $this->flattenPassport($goldEstimates, $matches, forGold: true),
                $this->flattenPassport($predictedEstimates, $matches, forGold: false),
                array_combine(self::PASSPORT_FIELD_MAP, self::PASSPORT_FIELD_MAP),
                self::PASSPORT_NUMERIC_FIELDS,
            ),
            'totals' => $this->aggregateFieldMetrics(
                $this->flattenTotals($goldEstimates, $matches, forGold: true),
                $this->flattenTotals($predictedEstimates, $matches, forGold: false),
                array_combine(self::TOTALS_FIELD_MAP, self::TOTALS_FIELD_MAP),
                self::TOTALS_NUMERIC_FIELDS,
            ),
            'quality' => $this->qualityCoverage($predictedRows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     * @return list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>
     */
    public function matchEstimates(array $goldEstimates, array $predictedEstimates): array
    {
        return $this->matcher->matchEstimates($goldEstimates, $predictedEstimates);
    }

    public function fieldsMatch(mixed $gold, mixed $predicted, bool $numeric): bool
    {
        return $this->matcher->fieldsMatch($gold, $predicted, $numeric);
    }

    public function isPresent(mixed $value): bool
    {
        return $this->matcher->isPresent($value);
    }

    public function valuesMatch(mixed $gold, mixed $predicted, bool $numeric): bool
    {
        return $this->matcher->valuesMatch($gold, $predicted, $numeric);
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<string, array<string, mixed>>
     */
    public function flattenRows(array $estimates, array $matches, bool $forGold): array
    {
        return $this->matcher->flattenRows($estimates, $matches, $forGold);
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<int, array<string, mixed>>
     */
    public function flattenPassport(array $estimates, array $matches, bool $forGold): array
    {
        return $this->matcher->flattenPassport($estimates, $matches, $forGold);
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>
     */
    public function flattenTotals(array $estimates, array $matches, bool $forGold): array
    {
        return $this->matcher->flattenTotals($estimates, $matches, $forGold);
    }

    /**
     * @param  array<string, array<string, mixed>>  $predictedRows
     * @return array{marked_rows: int, item_rows: int, fill_rate: float, by_reason: array<string, int>}
     */
    private function qualityCoverage(array $predictedRows): array
    {
        $byReason = [];

        foreach (RowQualityReason::cases() as $reason) {
            if ($reason->isModelOrigin()) {
                $byReason[$reason->value] = 0;
            }
        }

        $itemRows = 0;
        $markedRows = 0;

        foreach ($predictedRows as $row) {
            if (($row['row_type'] ?? null) !== self::QUALITY_ROW_TYPE) {
                continue;
            }

            $itemRows++;

            $quality = $row['quality'] ?? null;
            $rawReason = is_array($quality) ? ($quality['reason'] ?? null) : null;
            $reason = is_string($rawReason) ? RowQualityReason::tryFrom($rawReason) : null;

            if ($reason === null || ! $reason->isModelOrigin()) {
                continue;
            }

            $markedRows++;
            $byReason[$reason->value] = ($byReason[$reason->value] ?? 0) + 1;
        }

        return [
            'marked_rows' => $markedRows,
            'item_rows' => $itemRows,
            'fill_rate' => $itemRows === 0 ? 0.0 : round($markedRows / $itemRows, self::SCALE),
            'by_reason' => $byReason,
        ];
    }

    /**
     * @param  array{tp: int, fp: int, fn: int}  $tally
     * @return array{tp: int, fp: int, fn: int}
     */
    private function tallyField(array $tally, mixed $goldValue, mixed $predictedValue, bool $numeric): array
    {
        $goldPresent = $this->isPresent($goldValue);
        $predictedPresent = $this->isPresent($predictedValue);
        $match = $goldPresent && $predictedPresent && $this->valuesMatch($goldValue, $predictedValue, $numeric);

        if ($match) {
            $tally['tp']++;

            return $tally;
        }

        if ($predictedPresent) {
            $tally['fp']++;
        }

        if ($goldPresent) {
            $tally['fn']++;
        }

        return $tally;
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $goldFlat
     * @param  array<string|int, array<string, mixed>>  $predictedFlat
     * @param  array<string, string>  $fieldMap
     * @param  list<string>  $numericFields
     * @return array<string, array{precision: float, recall: float, f1: float}>
     */
    private function aggregateFieldMetrics(array $goldFlat, array $predictedFlat, array $fieldMap, array $numericFields): array
    {
        $counts = $this->tallyFieldMap($goldFlat, $predictedFlat, $fieldMap, $numericFields);

        return array_map(fn (array $tally): array => $this->precisionRecallF1($tally['tp'], $tally['fp'], $tally['fn']), $counts);
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $goldFlat
     * @param  array<string|int, array<string, mixed>>  $predictedFlat
     * @param  array<string, string>  $fieldMap
     * @param  list<string>  $numericFields
     * @return array<string, array{tp: int, fp: int, fn: int}>
     */
    private function tallyFieldMap(array $goldFlat, array $predictedFlat, array $fieldMap, array $numericFields): array
    {
        /** @var array<string, array{tp: int, fp: int, fn: int}> $counts */
        $counts = [];

        foreach ($fieldMap as $metric => $_) {
            $counts[$metric] = $this->emptyTally();
        }

        foreach ($goldFlat as $key => $goldFields) {
            $predictedFields = $predictedFlat[$key] ?? [];

            foreach ($fieldMap as $metric => $sourceField) {
                $numeric = in_array($metric, $numericFields, true);
                $counts[$metric] = $this->tallyField($counts[$metric], $goldFields[$sourceField] ?? null, $predictedFields[$sourceField] ?? null, $numeric);
            }
        }

        foreach ($predictedFlat as $key => $predictedFields) {
            if (isset($goldFlat[$key])) {
                continue;
            }

            foreach ($fieldMap as $metric => $sourceField) {
                if ($this->isPresent($predictedFields[$sourceField] ?? null)) {
                    $counts[$metric] = $this->incrementFalsePositive($counts[$metric]);
                }
            }
        }

        return $counts;
    }

    /** @return array{tp: int, fp: int, fn: int} */
    private function emptyTally(): array
    {
        return ['tp' => 0, 'fp' => 0, 'fn' => 0];
    }

    /**
     * @param  array{tp: int, fp: int, fn: int}  $tally
     * @return array{tp: int, fp: int, fn: int}
     */
    private function incrementFalsePositive(array $tally): array
    {
        $tally['fp']++;

        return $tally;
    }

    public function numbersEqual(string $a, string $b): bool
    {
        return $this->matcher->numbersEqual($a, $b);
    }

    /**
     * @return array{precision: float, recall: float, f1: float}
     */
    private function precisionRecallF1(int $tp, int $fp, int $fn): array
    {
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 1.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 1.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [
            'precision' => round($precision, self::SCALE),
            'recall' => round($recall, self::SCALE),
            'f1' => round($f1, self::SCALE),
        ];
    }
}
