<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Estimate\RowQualityReason;

/**
 * Метрики офлайн-оценки качества извлечения (ТЗ 04 §5): field-level precision/recall/F1
 * на паре (row_index, field) + row coverage. Чистая функция над УЖЕ нормализованными массивами
 * `{"estimates": [{..PassportResponse-поля.., "rows": [EstimateRow-массивы]}]}` — та же форма,
 * что gold (`docs/specs/v2/08-estimate-extraction.md` §8) и `analysis.result.estimates[]`
 * (`ExtractionOrchestrator`), поэтому сравнение не требует отдельного маппера.
 *
 * Правила метрик и парного сопоставления принадлежат этому классу.
 * `passport`/`totals` — отдельные ключи; `per_field`/`overall`/`row_coverage` сохраняют
 * форму для `estimate:eval:run` и бенчмарка. `quality` измеряет заполненность пометок
 * неопределённости модели ({@see self::qualityCoverage}) и не входит в `FIELD_MAP` или F1.
 * `EstimateDiffReport` переиспользует `flattenRows`/`flattenPassport`/`flattenTotals`/
 * `fieldsMatch`, чтобы диагностика и агрегированные метрики следовали одним правилам.
 *
 * Сметы сопоставляются ЯВНЫМ ПАРНЫМ ПРОХОДОМ (единственный носитель правила —
 * {@see self::matchEstimates}): порядок правил `estimate_number` → `passport.address` →
 * позиция среди оставшихся несопоставленными, каждое детерминированно и перечислимо. Строки
 * внутри сметы сопоставляются по `row_index` (уникален в рамках сметы); строки
 * итогов — по `(нормализованный label, kind)` (`docs/specs/v2/08-estimate-extraction.md` §8.6).
 */
final readonly class EvalMetrics
{
    private const string TOLERANCE = '0.01';

    private const int SCALE = 4;

    /**
     * Метрический ключ → поле строки (`EstimateRow::toArray`); "price" — доменное имя v1
     * (Alias «Стоимость всего»/«Итого руб», §1.4) для `total_price_curr` (переименовано из
     * `total_price`, research/08-schema-change-impact.md §4). Новые поля  (`unit_price_base`/
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
     * Паспорт-уровень сравнения (`research/08-schema-change-impact.md` §4): скалярные поля
     * `PassportResponse` напрямую + вложенные поля `EstimatePassport` через `passport.`-путь.
     * `work_types` не сравнивается — боевой LLM-ответ всегда несёт пустой список по построению
     * (исключение из общего правила, докблок `PassportResponse::$work_types`), а `totals` сравнивается
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
     * Поля одной строки `TotalLine` (footer сметы, включая `chapter_number`/`rate` рекурсивных итогов), сравниваемые ПОСЛЕ сопоставления строк по `(нормализованный label, kind)`.
     *
     * @var list<string>
     */
    public const array TOTALS_FIELD_MAP = ['value', 'kind', 'chapter_number', 'rate'];

    /**
     * @var list<string>
     */
    public const array TOTALS_NUMERIC_FIELDS = ['value', 'rate'];

    /**
     * Строка, на которой пометка неопределённости вообще имеет носителя: только `item`
     * материализуется в `prc_estimate_items`, поэтому знаменатель
     * заполненности считается по позициям — тем же множеством, на котором сформулированы
     * инварианты заполненности пометок модели.
     */
    private const string QUALITY_ROW_TYPE = 'item';

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

        /** @var array<string, array{tp: int, fp: int, fn: int}> $counts */
        $counts = [];

        foreach (self::FIELD_MAP as $metric => $_) {
            $counts[$metric] = $this->emptyTally();
        }

        $matchedGoldRows = 0;

        foreach ($goldRows as $key => $goldRow) {
            $predictedRow = $predictedRows[$key] ?? null;

            if ($predictedRow !== null) {
                $matchedGoldRows++;
            }

            foreach (self::FIELD_MAP as $metric => $dtoField) {
                $numeric = in_array($metric, self::ROW_NUMERIC_FIELDS, true);
                $counts[$metric] = $this->tallyField($counts[$metric], $goldRow[$dtoField] ?? null, $predictedRow[$dtoField] ?? null, $numeric);
            }
        }

        foreach ($predictedRows as $key => $predictedRow) {
            if (isset($goldRows[$key])) {
                continue; // уже учтён выше как совпавшая/несовпавшая пара
            }

            foreach (self::FIELD_MAP as $metric => $dtoField) {
                if ($this->isPresent($predictedRow[$dtoField] ?? null)) {
                    $counts[$metric] = $this->incrementFalsePositive($counts[$metric]);
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
     * Заполненность пометок неопределённости, поставленных МОДЕЛЬЮ (`research/12-uncertainty-
     * model.md` §9): доля позиций предсказания с непустой `quality` + разбивка по причинам.
     * НЕ участвует в {@see self::FIELD_MAP} и в F1: у деградированного эталона extraction-gold
     * нет, сравнивать пометку не с чем — метрика на всегда-пустом поле дала бы `f1 = 1.0` на пустом
     * месте .
     *
     * **Провенанса в разбивке нет и он не заводится.** Eval сравнивает
     * `analysis->result['estimates']` с gold, а в `result` попадает ТОЛЬКО вывод модели после сшивки
     * ({@see ExtractionOrchestrator}); вердикт {@see EstimateValidator} живёт в `ValidationReport` и
     * уходит в БД внутри персистера. Ветка «валидатор» печатала бы структурный ноль на КАЖДОМ
     * реальном прогоне независимо от того, работает валидатор или нет. Поэтому `by_reason`
     * перечисляет РОВНО model-origin причины ({@see RowQualityReason::isModelOrigin}), а строка с
     * причиной вне этого подмножества (контракт её отклоняет — `EstimateRow::validateAndCreate`)
     * не считается пометкой модели вовсе: `marked_rows` = сумма `by_reason` всегда.
     *
     * @param  array<string, array<string, mixed>>  $predictedRows  результат {@see self::flattenRows()}
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
     * Явный парный проход «gold ↔ предсказание» — единственный носитель правила
     * сопоставления смет, цитируется {@see EstimateDiffReport} через флэттен-методы ниже, не
     * пересчитывается им заново. Порядок правил, КАЖДОЕ детерминированно и перечислимо:
     * 1) точное совпадение нормализованного непустого `estimate_number`;
     * 2) точное совпадение нормализованного непустого `passport.address`;
     * 3) позиция среди оставшихся несопоставленными (относительный порядок сохраняется).
     * Нормализация — {@see self::normalizeText} (trim + схлопывание пробелов + регистронезависимо),
     * новая не изобретается. Дубль нормализованного значения на ОДНОЙ стороне делает его
     * неинформативным для ВСЕХ носителей на этой стороне (молчаливый выбор первого совпадения
     * дал бы разное сопоставление при разном порядке входа) — такие сметы остаются
     * несопоставленными этим правилом и падают на следующее. Непарная смета (после всех трёх
     * правил) получает собственный слот без пары — она считается ЦЕЛИКОМ (FN у gold, FP у
     * предсказания), а не молча теряется.
     *
     * @param  list<array<string, mixed>>  $goldEstimates
     * @param  list<array<string, mixed>>  $predictedEstimates
     * @return list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}> `rule`
     *                                                                                        ∈ `estimate_number`|`address`|`position`|`null` (`null` — пары нет)
     */
    public function matchEstimates(array $goldEstimates, array $predictedEstimates): array
    {
        $unpairedGold = array_keys($goldEstimates);
        $unpairedPredicted = array_keys($predictedEstimates);

        $numberMatch = $this->matchByExactKey(
            $unpairedGold,
            $unpairedPredicted,
            $this->normalizedKeyMap($goldEstimates, static fn (array $estimate): mixed => $estimate['estimate_number'] ?? null),
            $this->normalizedKeyMap($predictedEstimates, static fn (array $estimate): mixed => $estimate['estimate_number'] ?? null),
            'estimate_number',
        );

        $addressMatch = $this->matchByExactKey(
            $numberMatch['unpairedGold'],
            $numberMatch['unpairedPredicted'],
            $this->normalizedKeyMap($goldEstimates, static fn (array $estimate): mixed => is_array($estimate['passport'] ?? null) ? $estimate['passport']['address'] ?? null : null),
            $this->normalizedKeyMap($predictedEstimates, static fn (array $estimate): mixed => is_array($estimate['passport'] ?? null) ? $estimate['passport']['address'] ?? null : null),
            'address',
        );

        $unpairedGold = $addressMatch['unpairedGold'];
        $unpairedPredicted = $addressMatch['unpairedPredicted'];
        $positionCount = min(count($unpairedGold), count($unpairedPredicted));
        $positionPairs = [];

        for ($i = 0; $i < $positionCount; $i++) {
            $positionPairs[] = [$unpairedGold[$i], $unpairedPredicted[$i], 'position'];
        }

        /** @var list<array{gold_index: int|null, predicted_index: int|null, rule: string|null}> $entries */
        $entries = [];

        foreach ([...$numberMatch['pairs'], ...$addressMatch['pairs'], ...$positionPairs] as [$goldIndex, $predictedIndex, $rule]) {
            $entries[] = ['gold_index' => $goldIndex, 'predicted_index' => $predictedIndex, 'rule' => $rule];
        }

        foreach (array_slice($unpairedGold, $positionCount) as $goldIndex) {
            $entries[] = ['gold_index' => $goldIndex, 'predicted_index' => null, 'rule' => null];
        }

        foreach (array_slice($unpairedPredicted, $positionCount) as $predictedIndex) {
            $entries[] = ['gold_index' => null, 'predicted_index' => $predictedIndex, 'rule' => null];
        }

        usort($entries, static fn (array $a, array $b): int => [$a['gold_index'] ?? PHP_INT_MAX, $a['predicted_index'] ?? PHP_INT_MAX]
            <=> [$b['gold_index'] ?? PHP_INT_MAX, $b['predicted_index'] ?? PHP_INT_MAX]);

        /** @var list<array{slot: int, gold_index: int|null, predicted_index: int|null, rule: string|null}> $result */
        $result = [];

        foreach ($entries as $slot => $entry) {
            $result[] = ['slot' => $slot, ...$entry];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  callable(array<string, mixed>): mixed  $extractor
     * @return array<int, ?string> индекс сметы в `$estimates` → нормализованное непустое значение,
     *                             либо `null` (значение отсутствует/пустое)
     */
    private function normalizedKeyMap(array $estimates, callable $extractor): array
    {
        $map = [];

        foreach ($estimates as $index => $estimate) {
            $value = $extractor($estimate);
            $map[$index] = $this->isPresent($value) ? $this->normalizeText($this->scalarString($value)) : null;
        }

        return $map;
    }

    /**
     * Один проход правила «точное совпадение нормализованного непустого ключа» — дубль значения
     * на ОДНОЙ стороне исключает его из этого прохода для ВСЕХ носителей на этой стороне
     * (см. докблок {@see self::matchEstimates}).
     *
     * @param  list<int>  $unpairedGold
     * @param  list<int>  $unpairedPredicted
     * @param  array<int, ?string>  $goldKeys
     * @param  array<int, ?string>  $predictedKeys
     * @return array{pairs: list<array{0: int, 1: int, 2: string}>, unpairedGold: list<int>, unpairedPredicted: list<int>}
     */
    private function matchByExactKey(array $unpairedGold, array $unpairedPredicted, array $goldKeys, array $predictedKeys, string $rule): array
    {
        $goldCounts = [];

        foreach ($unpairedGold as $goldIndex) {
            $key = $goldKeys[$goldIndex] ?? null;

            if ($key !== null) {
                $goldCounts[$key] = ($goldCounts[$key] ?? 0) + 1;
            }
        }

        $predictedCounts = [];
        $predictedIndexByKey = [];

        foreach ($unpairedPredicted as $predictedIndex) {
            $key = $predictedKeys[$predictedIndex] ?? null;

            if ($key !== null) {
                $predictedCounts[$key] = ($predictedCounts[$key] ?? 0) + 1;
                $predictedIndexByKey[$key] = $predictedIndex;
            }
        }

        $pairs = [];
        $matchedGold = [];
        $matchedPredicted = [];

        foreach ($unpairedGold as $goldIndex) {
            $key = $goldKeys[$goldIndex] ?? null;

            if ($key === null || $goldCounts[$key] !== 1 || ($predictedCounts[$key] ?? 0) !== 1) {
                continue;
            }

            $predictedIndex = $predictedIndexByKey[$key];
            $pairs[] = [$goldIndex, $predictedIndex, $rule];
            $matchedGold[] = $goldIndex;
            $matchedPredicted[] = $predictedIndex;
        }

        return [
            'pairs' => $pairs,
            'unpairedGold' => array_values(array_diff($unpairedGold, $matchedGold)),
            'unpairedPredicted' => array_values(array_diff($unpairedPredicted, $matchedPredicted)),
        ];
    }

    /**
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @return array<int, int> индекс сметы своей стороны (`$forGold` — gold/предсказание) → номер слота
     */
    private function slotByOriginalIndex(array $matches, bool $forGold): array
    {
        $side = $forGold ? 'gold_index' : 'predicted_index';
        $map = [];

        foreach ($matches as $entry) {
            if ($entry[$side] !== null) {
                $map[$entry[$side]] = $entry['slot'];
            }
        }

        return $map;
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
            return $this->numbersEqual($this->scalarString($gold), $this->scalarString($predicted));
        }

        return $this->normalizeText($this->scalarString($gold)) === $this->normalizeText($this->scalarString($predicted));
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     *                                                                                                   {@see self::matchEstimates}
     * @return array<string, array<string, mixed>> ключ "{слот}:{row_index}"
     */
    public function flattenRows(array $estimates, array $matches, bool $forGold): array
    {
        $slotByIndex = $this->slotByOriginalIndex($matches, $forGold);
        $flat = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $slot = $slotByIndex[$estimateIndex] ?? null;

            if ($slot === null) {
                continue;
            }

            $rows = $estimate['rows'] ?? [];

            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                /** @var array<string, mixed> $row */
                $rowIndex = $row['row_index'] ?? null;

                if (! is_int($rowIndex) && ! is_string($rowIndex)) {
                    continue;
                }

                $flat["{$slot}:{$rowIndex}"] = $row;
            }
        }

        return $flat;
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     *                                                                                                   {@see self::matchEstimates}
     * @return array<int, array<string, mixed>> ключ — номер слота; значение — плоская карта
     *                                          `PASSPORT_FIELD_MAP`
     */
    public function flattenPassport(array $estimates, array $matches, bool $forGold): array
    {
        $slotByIndex = $this->slotByOriginalIndex($matches, $forGold);
        $flat = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $slot = $slotByIndex[$estimateIndex] ?? null;

            if ($slot === null) {
                continue;
            }

            $passport = (array) ($estimate['passport'] ?? []);

            $flat[$slot] = [
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
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     *                                                                                                   {@see self::matchEstimates}
     * @return array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>
     *                                                                                                                                 `estimate_index` — номер слота, не индекс списка `estimates`
     */
    public function flattenTotals(array $estimates, array $matches, bool $forGold): array
    {
        $slotByIndex = $this->slotByOriginalIndex($matches, $forGold);
        $flat = [];

        foreach ($estimates as $estimateIndex => $estimate) {
            $slot = $slotByIndex[$estimateIndex] ?? null;

            if ($slot === null) {
                continue;
            }

            $totals = $estimate['totals'] ?? [];
            $footer = is_array($totals) && is_array($totals['footer'] ?? null) ? array_values($totals['footer']) : [];
            $this->flattenTotalLines($footer, $slot, $flat);
        }

        return $flat;
    }

    /**
     * @param  list<mixed>  $lines
     * @param  array<string, array{estimate_index: int, label: string, value: mixed, kind: mixed, chapter_number: mixed, rate: mixed}>  $flat
     */
    private function flattenTotalLines(array $lines, int $slot, array &$flat): void
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            /** @var array<string, mixed> $line */
            $label = is_string($line['label'] ?? null) ? $line['label'] : '';
            $kind = $line['kind'] ?? null;
            $key = $slot.'::'.$this->normalizeText($label).'::'.($this->scalarString($kind ?? ''));

            $flat[$key] = [
                'estimate_index' => $slot,
                'label' => $label,
                'value' => $line['value'] ?? null,
                'kind' => $kind,
                'chapter_number' => $line['chapter_number'] ?? null,
                'rate' => $line['rate'] ?? null,
            ];

            $children = $line['children'] ?? [];
            $this->flattenTotalLines(is_array($children) ? array_values($children) : [], $slot, $flat);
        }
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
     * Тот же tp/fp/fn-подсчёт, что построчный блок `compare`, но над произвольной плоской картой
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

        return array_map(fn (array $tally): array => $this->precisionRecallF1($tally['tp'], $tally['fp'], $tally['fn']), $counts);
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
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

    private function scalarString(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new \RuntimeException('Метрика ожидает скалярное значение поля.');
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
