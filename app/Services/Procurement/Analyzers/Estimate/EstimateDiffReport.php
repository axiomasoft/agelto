<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Ai\Diff\DiffEntry;
use App\Dto\Procurement\Ai\Diff\DiffReport;
use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\EstimateTotalsBlock;

/**
 * Детерминированный diff-отчёт «gold vs предсказание» (P7.3, `docs/specs/v2/
 * 08-estimate-extraction.md` §8.6, D62): вход для человека, для критика (P7.4) и для экрана
 * сравнения (P8.3). ЧИСТАЯ функция над массивами формы §8.2 (та же форма, что
 * `analysis.result.estimates[]`) — ни БД, ни сети, ни файлов, как {@see EvalMetrics}.
 *
 * Правило сопоставления и критерий совпадения значений — ОДИН носитель ({@see EvalMetrics}):
 * этот сервис только цитирует его `flattenRows()`/`flattenPassport()`/`flattenTotals()`/
 * `fieldsMatch()`, не пересчитывает их заново (иначе диагностика и агрегат метрики разъехались бы
 * при первой же правке правила сопоставления, `plan-protocol` §3). Арифметика — тем же приёмом
 * через {@see EstimateValidator}, отдельно по gold и по предсказанию: базисно-индексный расчёт
 * законно не сходится и у эталона (D42), отчёт обязан различать «модель ошиблась» и «документ
 * такой».
 */
final readonly class EstimateDiffReport
{
    public function __construct(
        private EvalMetrics $metrics,
        private EstimateValidator $validator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     */
    public function build(array $goldEstimates, array $predictedEstimates, ?int $limitRows = 200): DiffReport
    {
        $summary = $this->metrics->compare($goldEstimates, $predictedEstimates);

        $rowEntries = $this->diffRows($goldEstimates, $predictedEstimates);
        $totalRowEntries = count($rowEntries);
        $shownRowEntries = $limitRows === null ? $rowEntries : array_slice($rowEntries, 0, $limitRows);

        $passportEntries = $this->diffPassport($goldEstimates, $predictedEstimates);
        $totalsEntries = $this->diffTotals($goldEstimates, $predictedEstimates);
        $arithmetic = $this->diffArithmetic($goldEstimates, $predictedEstimates);

        return new DiffReport(
            summary: $summary,
            rows: $shownRowEntries,
            passport: $passportEntries,
            totals: $totalsEntries,
            arithmetic: $arithmetic,
            truncated: ['shown' => count($shownRowEntries), 'total' => $totalRowEntries],
            verdict: $this->verdict($totalRowEntries, $passportEntries, $totalsEntries, $arithmetic),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $gold
     * @param  list<array<string, mixed>>  $predicted
     * @return list<DiffEntry>
     */
    private function diffRows(array $gold, array $predicted): array
    {
        $goldFlat = $this->metrics->flattenRows($gold);
        $predictedFlat = $this->metrics->flattenRows($predicted);

        $entries = [];

        foreach ($goldFlat as $key => $goldRow) {
            [$estimateIndex, $rowIndex] = $this->splitRowKey($key);
            $predictedRow = $predictedFlat[$key] ?? null;

            if ($predictedRow === null) {
                $entries[] = new DiffEntry($estimateIndex, $rowIndex, 'missing', null, $goldRow, null);

                continue;
            }

            foreach (EvalMetrics::FIELD_MAP as $metric => $dtoField) {
                $goldValue = $goldRow[$dtoField] ?? null;
                $predictedValue = $predictedRow[$dtoField] ?? null;
                $numeric = in_array($metric, EvalMetrics::ROW_NUMERIC_FIELDS, true);

                if (! $this->metrics->fieldsMatch($goldValue, $predictedValue, $numeric)) {
                    $entries[] = new DiffEntry($estimateIndex, $rowIndex, 'field_mismatch', $metric, $goldValue, $predictedValue);
                }
            }
        }

        foreach ($predictedFlat as $key => $predictedRow) {
            if (isset($goldFlat[$key])) {
                continue;
            }

            [$estimateIndex, $rowIndex] = $this->splitRowKey($key);
            $entries[] = new DiffEntry($estimateIndex, $rowIndex, 'extra', null, null, $predictedRow);
        }

        usort($entries, static fn (DiffEntry $a, DiffEntry $b): int => [$a->estimateIndex, $a->rowIndex, $a->field ?? '']
            <=> [$b->estimateIndex, $b->rowIndex, $b->field ?? '']);

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $gold
     * @param  list<array<string, mixed>>  $predicted
     * @return list<DiffEntry>
     */
    private function diffPassport(array $gold, array $predicted): array
    {
        $goldFlat = $this->metrics->flattenPassport($gold);
        $predictedFlat = $this->metrics->flattenPassport($predicted);

        $entries = [];

        foreach ($goldFlat as $estimateIndex => $goldFields) {
            $predictedFields = $predictedFlat[$estimateIndex] ?? [];

            foreach (EvalMetrics::PASSPORT_FIELD_MAP as $field) {
                $goldValue = $goldFields[$field] ?? null;
                $predictedValue = $predictedFields[$field] ?? null;
                $numeric = in_array($field, EvalMetrics::PASSPORT_NUMERIC_FIELDS, true);

                if (! $this->metrics->fieldsMatch($goldValue, $predictedValue, $numeric)) {
                    $entries[] = new DiffEntry($estimateIndex, null, 'field_mismatch', $field, $goldValue, $predictedValue);
                }
            }
        }

        usort($entries, static fn (DiffEntry $a, DiffEntry $b): int => [$a->estimateIndex, $a->field] <=> [$b->estimateIndex, $b->field]);

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $gold
     * @param  list<array<string, mixed>>  $predicted
     * @return list<DiffEntry>
     */
    private function diffTotals(array $gold, array $predicted): array
    {
        $goldFlat = $this->metrics->flattenTotals($gold);
        $predictedFlat = $this->metrics->flattenTotals($predicted);

        $entries = [];

        foreach ($goldFlat as $key => $goldLine) {
            $estimateIndex = (int) $goldLine['estimate_index'];
            $predictedLine = $predictedFlat[$key] ?? null;

            if ($predictedLine === null) {
                $entries[] = new DiffEntry($estimateIndex, null, 'missing', "totals.{$goldLine['label']}", $goldLine, null);

                continue;
            }

            foreach (EvalMetrics::TOTALS_FIELD_MAP as $field) {
                $goldValue = $goldLine[$field] ?? null;
                $predictedValue = $predictedLine[$field] ?? null;
                $numeric = in_array($field, EvalMetrics::TOTALS_NUMERIC_FIELDS, true);

                if (! $this->metrics->fieldsMatch($goldValue, $predictedValue, $numeric)) {
                    $entries[] = new DiffEntry($estimateIndex, null, 'field_mismatch', "totals.{$goldLine['label']}.{$field}", $goldValue, $predictedValue);
                }
            }
        }

        foreach ($predictedFlat as $key => $predictedLine) {
            if (isset($goldFlat[$key])) {
                continue;
            }

            $entries[] = new DiffEntry((int) $predictedLine['estimate_index'], null, 'extra', "totals.{$predictedLine['label']}", null, $predictedLine);
        }

        usort($entries, static fn (DiffEntry $a, DiffEntry $b): int => [$a->estimateIndex, $a->field] <=> [$b->estimateIndex, $b->field]);

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $gold
     * @param  list<array<string, mixed>>  $predicted
     * @return list<array{estimate_index: int, predicted: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null, gold: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null}>
     */
    private function diffArithmetic(array $gold, array $predicted): array
    {
        $count = max(count($gold), count($predicted));

        $result = [];

        for ($estimateIndex = 0; $estimateIndex < $count; $estimateIndex++) {
            $result[] = [
                'estimate_index' => $estimateIndex,
                'predicted' => $this->validateEstimate($predicted[$estimateIndex] ?? null),
                'gold' => $this->validateEstimate($gold[$estimateIndex] ?? null),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $estimate
     * @return array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null
     */
    private function validateEstimate(?array $estimate): ?array
    {
        if ($estimate === null) {
            return null;
        }

        $rows = array_map(
            static fn (array $row): EstimateRow => EstimateRow::from($row),
            (array) ($estimate['rows'] ?? []),
        );

        $totals = isset($estimate['totals']) ? EstimateTotalsBlock::from((array) $estimate['totals']) : null;
        $rowsExpected = (int) ($estimate['rows_expected'] ?? 0);

        $report = $this->validator->validate($rowsExpected, $rows, $totals);

        return [
            'status' => $report->status->value,
            'anomalous_row_indexes' => $report->anomalousRowIndexes,
            'notes' => $report->notes,
        ];
    }

    /**
     * @param  list<DiffEntry>  $passportEntries
     * @param  list<DiffEntry>  $totalsEntries
     * @param  list<array{estimate_index: int, predicted: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null, gold: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null}>  $arithmetic
     */
    private function verdict(int $rowEntriesTotal, array $passportEntries, array $totalsEntries, array $arithmetic): string
    {
        $arithmeticAnomalies = count(array_filter(
            $arithmetic,
            static fn (array $pair): bool => ($pair['predicted']['status'] ?? 'valid') !== 'valid'
                || ($pair['gold']['status'] ?? 'valid') !== 'valid',
        ));

        if ($rowEntriesTotal === 0 && $passportEntries === [] && $totalsEntries === [] && $arithmeticAnomalies === 0) {
            return 'расхождений нет';
        }

        return sprintf(
            'расхождений: строки %d · паспорт %d · итоги %d · арифметика с аномалией %d из %d смет',
            $rowEntriesTotal,
            count($passportEntries),
            count($totalsEntries),
            $arithmeticAnomalies,
            count($arithmetic),
        );
    }

    /**
     * @return array{0: int, 1: int} [estimateIndex, rowIndex] — ключ `EvalMetrics::flattenRows()`
     *                               гарантированно "{int}:{int}", разбор строкой безопасен.
     */
    private function splitRowKey(string $key): array
    {
        [$estimateIndex, $rowIndex] = explode(':', $key, 2);

        return [(int) $estimateIndex, (int) $rowIndex];
    }
}
