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
 * Единственный владелец метрики (P7.3, D38/D62/D84): P4 её сознательно не трогает, `P6.1`/`P7.5`
 * только читают. Расширена P7.3 картой следствий `research/08-schema-change-impact.md` §4 —
 * `passport`/`totals` добавлены ОТДЕЛЬНЫМИ ключами, `per_field`/`overall`/`row_coverage` сохраняют
 * форму (их читают `ai:eval:run` и бенчмарк P6.1). `EstimateDiffReport` (P7.3) переиспользует
 * `flattenRows()`/`flattenPassport()`/`flattenTotals()`/`fieldsMatch()` как ОДИН носитель правил
 * сопоставления и сравнения значений — второй такой носитель разъехался бы с этим при первой же
 * правке (`plan-protocol` §3).
 *
 * Сметы сопоставляются ПОЗИЦИОННО (индекс в списке `estimates`, соответствующий возрастающему
 * `ordinal`) — эвристика сопоставления по номеру/заголовку не входит в Scope этого item'а
 * (Pending Work при появлении многосметных эталонов, `research/08-schema-change-impact.md` §7).
 * Строки внутри сметы сопоставляются по `row_index` (уникален в рамках сметы, D10/§4а); строки
 * итогов — по `(нормализованный label, kind)` (P7.3, `docs/specs/v2/08-estimate-extraction.md` §8.6).
 */
final readonly class EvalMetrics
{
    private const string TOLERANCE = '0.01';

    private const int SCALE = 4;

    /**
     * Метрический ключ → поле строки (`EstimateRow::toArray()`); "price" — доменное имя v1
     * (Alias «Стоимость всего»/«Итого руб», §1.4) для `total_price_curr` (переименовано из
     * `total_price` D93, research/08-schema-change-impact.md §4). Новые поля P4 (`unit_price_base`/
     * `total_price_base`/`index_code`/`index_value`/`source_position_number`/`work_type_name`)
     * добавлены той же строкой — метрический ключ совпадает с именем поля DTO.
     *
     * @var array<string, string>
     */
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

    /**
     * Metric-ключи `FIELD_MAP`, сравниваемые как decimal (bcmath ±{@see TOLERANCE}) — остальные
     * ключи сравниваются текстом (trim + схлопывание пробелов + регистронезависимо).
     *
     * @var list<string>
     */
    public const array ROW_NUMERIC_FIELDS = ['quantity', 'price', 'unit_price_base', 'total_price_base', 'index_value'];

    /**
     * Паспорт-уровень сравнения (P7.3, `research/08-schema-change-impact.md` §4): скалярные поля
     * `PassportResponse` напрямую + вложенные поля `EstimatePassport` через `passport.`-путь.
     * `work_types` не сравнивается — боевой LLM-ответ всегда несёт пустой список по построению
     * (исключение из D33, докблок `PassportResponse::$work_types`), а `totals` сравнивается
     * отдельно (`TOTALS_FIELD_MAP`).
     *
     * @var list<string>
     */
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

    /**
     * @var list<string>
     */
    public const array PASSPORT_NUMERIC_FIELDS = ['labor_hours_workers', 'labor_hours_machinists'];

    /**
     * Поля одной строки `TotalLine` (footer сметы, рекурсивно — `chapter_number`/`rate` дополнены
     * P4.5), сравниваемые ПОСЛЕ сопоставления строк по `(нормализованный label, kind)`.
     *
     * @var list<string>
     */
    public const array TOTALS_FIELD_MAP = ['value', 'kind', 'chapter_number', 'rate'];

    /**
     * @var list<string>
     */
    public const array TOTALS_NUMERIC_FIELDS = ['value', 'rate'];

    /**
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     * @return array{
     *     per_field: array<string, array{precision: float, recall: float, f1: float}>,
     *     overall: array{precision: float, recall: float, f1: float},
     *     row_coverage: float,
     *     passport: array<string, array{precision: float, recall: float, f1: float}>,
     *     totals: array<string, array{precision: float, recall: float, f1: float}>,
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
                $numeric = in_array($metric, self::ROW_NUMERIC_FIELDS, true);
                $this->tallyField($counts[$metric], $goldRow[$dtoField] ?? null, $predictedRow[$dtoField] ?? null, $numeric);
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
            'passport' => $this->aggregateFieldMetrics(
                $this->flattenPassport($goldEstimates),
                $this->flattenPassport($predictedEstimates),
                array_combine(self::PASSPORT_FIELD_MAP, self::PASSPORT_FIELD_MAP),
                self::PASSPORT_NUMERIC_FIELDS,
            ),
            'totals' => $this->aggregateFieldMetrics(
                $this->flattenTotals($goldEstimates),
                $this->flattenTotals($predictedEstimates),
                array_combine(self::TOTALS_FIELD_MAP, self::TOTALS_FIELD_MAP),
                self::TOTALS_NUMERIC_FIELDS,
            ),
        ];
    }

    /**
     * Оба значения отсутствуют — не расхождение (нечего сравнивать); один присутствует, другой
     * нет, либо оба присутствуют, но не совпадают по значению — расхождение. Переиспользуется
     * `EstimateDiffReport` для решения `field_mismatch` (тот же критерий совпадения, что здесь
     * формирует tp/fp/fn — единственный носитель правила).
     */
    public function fieldsMatch(mixed $gold, mixed $predicted, bool $numeric): bool
    {
        $goldPresent = $this->isPresent($gold);
        $predictedPresent = $this->isPresent($predicted);

        if (! $goldPresent && ! $predictedPresent) {
            return true;
        }

        return $goldPresent && $predictedPresent && $this->valuesMatch($gold, $predicted, $numeric);
    }

    public function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    public function valuesMatch(mixed $gold, mixed $predicted, bool $numeric): bool
    {
        if ($numeric) {
            return $this->numbersEqual((string) $gold, (string) $predicted);
        }

        return $this->normalizeText((string) $gold) === $this->normalizeText((string) $predicted);
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @return array<string, array<string, mixed>> ключ "{индекс сметы}:{row_index}"
     */
    public function flattenRows(array $estimates): array
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

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @return array<int, array<string, mixed>> ключ — индекс сметы; значение — плоская карта
     *                                          `PASSPORT_FIELD_MAP`
     */
    public function flattenPassport(array $estimates): array
    {
        $flat = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $passport = (array) ($estimate['passport'] ?? []);

            $flat[$estimateIndex] = [
                'estimate_number' => $estimate['estimate_number'] ?? null,
                'currency' => $estimate['currency'] ?? null,
                'base_year' => $estimate['base_year'] ?? null,
                'price_level' => $estimate['price_level'] ?? null,
                'calc_method' => $estimate['calc_method'] ?? null,
                'labor_hours_workers' => $estimate['labor_hours_workers'] ?? null,
                'labor_hours_machinists' => $estimate['labor_hours_machinists'] ?? null,
                'document_number' => $estimate['document_number'] ?? null,
                'document_date' => $estimate['document_date'] ?? null,
                'period_start' => $estimate['period_start'] ?? null,
                'period_end' => $estimate['period_end'] ?? null,
                'passport.type' => $passport['type'] ?? null,
                'passport.reference' => $passport['reference'] ?? null,
                'passport.target' => $passport['target'] ?? null,
                'passport.address' => $passport['address'] ?? null,
                'passport.generator' => $passport['generator'] ?? null,
                'passport.contract_number' => $passport['contract_number'] ?? null,
                'passport.contract_date' => $passport['contract_date'] ?? null,
            ];
        }

        return $flat;
    }

    /**
     * Рекурсивно разворачивает дерево `totals.footer` (включая `children`, «в том числе») каждой
     * сметы в плоскую карту, ключ — сопоставление строк по `(нормализованный label, kind)`
     * (`docs/specs/v2/08-estimate-extraction.md` §8.6): при совпадающей паре `(label, kind)` внутри
     * одной сметы позже встреченная строка перезаписывает раньше встреченную (тот же документ не
     * должен нести двух начислений с одинаковой подписью и типом — сопоставление проще, чем
     * порядковая эвристика по позиции в дереве).
     *
     * @param  list<array<string, mixed>>  $estimates
     * @return array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>
     */
    public function flattenTotals(array $estimates): array
    {
        $flat = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $footer = (array) ($estimate['totals']['footer'] ?? []);
            $this->flattenTotalLines($footer, $estimateIndex, $flat);
        }

        return $flat;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>  $flat
     */
    private function flattenTotalLines(array $lines, int $estimateIndex, array &$flat): void
    {
        foreach ($lines as $line) {
            $line = (array) $line;
            $label = (string) ($line['label'] ?? '');
            $kind = $line['kind'] ?? null;
            $key = $estimateIndex.'::'.$this->normalizeText($label).'::'.($kind ?? '');

            $flat[$key] = [
                'estimate_index' => $estimateIndex,
                'label' => $label,
                'value' => $line['value'] ?? null,
                'kind' => $kind,
                'chapter_number' => $line['chapter_number'] ?? null,
                'rate' => $line['rate'] ?? null,
            ];

            $this->flattenTotalLines((array) ($line['children'] ?? []), $estimateIndex, $flat);
        }
    }

    private function tallyField(array &$tally, mixed $goldValue, mixed $predictedValue, bool $numeric): void
    {
        $goldPresent = $this->isPresent($goldValue);
        $predictedPresent = $this->isPresent($predictedValue);
        $match = $goldPresent && $predictedPresent && $this->valuesMatch($goldValue, $predictedValue, $numeric);

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

    /**
     * Тот же tp/fp/fn-подсчёт, что построчный блок `compare()`, но над произвольной плоской картой
     * «ключ сопоставления → поля» (паспорт/итоги) — единый носитель агрегации вместо копии цикла.
     *
     * @param  array<string|int, array<string, mixed>>  $goldFlat
     * @param  array<string|int, array<string, mixed>>  $predictedFlat
     * @param  array<string, string>  $fieldMap  метрический ключ → ключ внутри плоской карты
     * @param  list<string>  $numericFields
     * @return array<string, array{precision: float, recall: float, f1: float}>
     */
    private function aggregateFieldMetrics(array $goldFlat, array $predictedFlat, array $fieldMap, array $numericFields): array
    {
        /** @var array<string, array{tp: int, fp: int, fn: int}> $counts */
        $counts = array_map(static fn (): array => ['tp' => 0, 'fp' => 0, 'fn' => 0], $fieldMap);

        foreach ($goldFlat as $key => $goldFields) {
            $predictedFields = $predictedFlat[$key] ?? [];

            foreach ($fieldMap as $metric => $sourceField) {
                $numeric = in_array($metric, $numericFields, true);
                $this->tallyField($counts[$metric], $goldFields[$sourceField] ?? null, $predictedFields[$sourceField] ?? null, $numeric);
            }
        }

        foreach ($predictedFlat as $key => $predictedFields) {
            if (isset($goldFlat[$key])) {
                continue;
            }

            foreach ($fieldMap as $metric => $sourceField) {
                if ($this->isPresent($predictedFields[$sourceField] ?? null)) {
                    $counts[$metric]['fp']++;
                }
            }
        }

        return array_map(fn (array $tally): array => $this->precisionRecallF1($tally['tp'], $tally['fp'], $tally['fn']), $counts);
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
}
