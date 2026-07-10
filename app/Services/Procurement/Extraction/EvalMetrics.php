<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

/**
 * Метрики офлайн-оценки качества извлечения (P7.4, ТЗ 04 §5): field-level precision/recall/F1
 * на паре (row_index, field) + row coverage. Чистая функция над УЖЕ нормализованными массивами
 * `{"estimates": [{..PassportResponse-поля.., "rows": [EstimateRow-массивы]}]}` — та же форма,
 * что gold (`docs/specs/v2/08-estimate-extraction.md` §8) и `analysis.result.estimates[]`
 * (`ExtractionOrchestrator`, P6.1/P6.4), поэтому сравнение не требует отдельного маппера.
 *
 * Сметы сопоставляются ПОЗИЦИОННО (индекс в списке `estimates`, соответствующий возрастающему
 * `ordinal`) — эвристика сопоставления по номеру/заголовку не входит в Scope этого item'а
 * (Pending Work при появлении многосметных эталонов). Строки внутри сметы сопоставляются по
 * `row_index` (уникален в рамках сметы, D10/§4а).
 */
final readonly class EvalMetrics
{
    private const string TOLERANCE = '0.01';

    private const int SCALE = 4;

    /**
     * Метрический ключ → поле строки (`EstimateRow::toArray()`); "price" — доменное имя v1
     * (Alias «Стоимость всего»/«Итого руб», §1.4) для `total_price`.
     *
     * @var array<string, string>
     */
    private const array FIELD_MAP = [
        'row_type' => 'row_type',
        'code' => 'code',
        'name' => 'name',
        'unit' => 'unit',
        'quantity' => 'quantity',
        'price' => 'total_price',
    ];

    /**
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     * @return array{
     *     per_field: array<string, array{precision: float, recall: float, f1: float}>,
     *     overall: array{precision: float, recall: float, f1: float},
     *     row_coverage: float,
     * }
     */
    public function compare(array $goldEstimates, array $predictedEstimates): array
    {
        $goldRows = $this->flattenRows($goldEstimates);
        $predictedRows = $this->flattenRows($predictedEstimates);

        /** @var array<string, array{tp: int, fp: int, fn: int}> $counts */
        $counts = array_map(static fn (): array => ['tp' => 0, 'fp' => 0, 'fn' => 0], self::FIELD_MAP);

        $matchedGoldRows = 0;

        foreach ($goldRows as $key => $goldRow) {
            $predictedRow = $predictedRows[$key] ?? null;

            if ($predictedRow !== null) {
                $matchedGoldRows++;
            }

            foreach (self::FIELD_MAP as $metric => $dtoField) {
                $this->tallyField($counts[$metric], $metric, $goldRow[$dtoField] ?? null, $predictedRow[$dtoField] ?? null);
            }
        }

        foreach ($predictedRows as $key => $predictedRow) {
            if (isset($goldRows[$key])) {
                continue; // уже учтён выше как совпавшая/несовпавшая пара
            }

            foreach (self::FIELD_MAP as $metric => $dtoField) {
                if ($this->isPresent($predictedRow[$dtoField] ?? null)) {
                    $counts[$metric]['fp']++;
                }
            }
        }

        $perField = [];
        $totalTp = 0;
        $totalFp = 0;
        $totalFn = 0;

        foreach ($counts as $metric => $tally) {
            $perField[$metric] = $this->precisionRecallF1($tally['tp'], $tally['fp'], $tally['fn']);
            $totalTp += $tally['tp'];
            $totalFp += $tally['fp'];
            $totalFn += $tally['fn'];
        }

        $rowCoverage = $goldRows === [] ? 1.0 : round($matchedGoldRows / count($goldRows), self::SCALE);

        return [
            'per_field' => $perField,
            'overall' => $this->precisionRecallF1($totalTp, $totalFp, $totalFn),
            'row_coverage' => $rowCoverage,
        ];
    }

    /**
     * @param  array{tp: int, fp: int, fn: int}  $tally
     */
    private function tallyField(array &$tally, string $metric, mixed $goldValue, mixed $predictedValue): void
    {
        $goldPresent = $this->isPresent($goldValue);
        $predictedPresent = $this->isPresent($predictedValue);
        $match = $goldPresent && $predictedPresent && $this->valuesMatch($metric, $goldValue, $predictedValue);

        if ($match) {
            $tally['tp']++;

            return;
        }

        if ($predictedPresent) {
            $tally['fp']++;
        }

        if ($goldPresent) {
            $tally['fn']++;
        }
    }

    private function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function valuesMatch(string $metric, mixed $gold, mixed $predicted): bool
    {
        if (in_array($metric, ['quantity', 'price'], true)) {
            return $this->numbersEqual((string) $gold, (string) $predicted);
        }

        return $this->normalizeText((string) $gold) === $this->normalizeText((string) $predicted);
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function numbersEqual(string $a, string $b): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return $this->normalizeText($a) === $this->normalizeText($b);
        }

        $diff = bcsub($a, $b, self::SCALE);
        $abs = bccomp($diff, '0', self::SCALE) < 0 ? bcmul($diff, '-1', self::SCALE) : $diff;

        return bccomp($abs, self::TOLERANCE, self::SCALE) <= 0;
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

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @return array<string, array<string, mixed>> ключ "{индекс сметы}:{row_index}"
     */
    private function flattenRows(array $estimates): array
    {
        $flat = [];

        foreach (array_values($estimates) as $estimateIndex => $estimate) {
            foreach ((array) ($estimate['rows'] ?? []) as $row) {
                $rowIndex = $row['row_index'] ?? null;

                if ($rowIndex === null) {
                    continue;
                }

                $flat["{$estimateIndex}:{$rowIndex}"] = (array) $row;
            }
        }

        return $flat;
    }
}
