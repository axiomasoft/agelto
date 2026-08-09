<?php

declare(strict_types=1);

use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;

/**
 * §8.4 `docs/03_Dev/ai/estimate-extraction.md` — field-level precision/recall/F1 + row coverage
 * на паре (row_index, field), без LLM (чистая функция над нормализованными массивами эталона/
 * предсказания в форме `analysis.result.estimates[]`).
 */
function goldRow(int $rowIndex, array $overrides = []): array
{
    return array_merge([
        'row_index' => $rowIndex,
        'row_type' => 'item',
        'name' => 'Устройство ввода',
        'code' => '111-222',
        'unit' => 'шт',
        'quantity' => '2',
        'total_price_curr' => '200.00',
    ], $overrides);
}

function estimatesOf(array $rows, array $estimateOverrides = []): array
{
    return [array_merge(['ordinal' => 1, 'rows' => $rows], $estimateOverrides)];
}

test('идентичное предсказание даёт precision=recall=f1=1.0 и полное row_coverage', function () {
    $gold = estimatesOf([goldRow(1), goldRow(2, ['name' => 'Кабель ВВГ'])]);
    $predicted = estimatesOf([goldRow(1), goldRow(2, ['name' => 'Кабель ВВГ'])]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['overall']['precision'])->toBe(1.0)
        ->and($metrics['overall']['recall'])->toBe(1.0)
        ->and($metrics['overall']['f1'])->toBe(1.0)
        ->and($metrics['row_coverage'])->toBe(1.0);
});

test('пропущенная строка предсказания снижает recall и row_coverage, но не трогает precision совпавших полей', function () {
    $gold = estimatesOf([goldRow(1), goldRow(2, ['name' => 'Кабель ВВГ'])]);
    $predicted = estimatesOf([goldRow(1)]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['overall']['recall'])->toBeLessThan(1.0)
        ->and($metrics['row_coverage'])->toBe(0.5)
        ->and($metrics['per_field']['name']['precision'])->toBe(1.0);
});

test('лишняя (галлюцинированная) строка предсказания снижает precision, но не row_coverage эталона', function () {
    $gold = estimatesOf([goldRow(1)]);
    $predicted = estimatesOf([goldRow(1), goldRow(2, ['name' => 'Придуманная позиция'])]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['overall']['precision'])->toBeLessThan(1.0)
        ->and($metrics['row_coverage'])->toBe(1.0);
});

test('числовое сравнение quantity/price — bcmath с допуском ±0.01', function () {
    $gold = estimatesOf([goldRow(1, ['total_price_curr' => '200.00'])]);
    $withinTolerance = estimatesOf([goldRow(1, ['total_price_curr' => '200.005'])]);
    $outsideTolerance = estimatesOf([goldRow(1, ['total_price_curr' => '201.00'])]);

    $ok = (new EvalMetrics)->compare($gold, $withinTolerance);
    $mismatch = (new EvalMetrics)->compare($gold, $outsideTolerance);

    expect($ok['per_field']['price']['f1'])->toBe(1.0)
        ->and($mismatch['per_field']['price']['f1'])->toBeLessThan(1.0);
});

test('текстовое сравнение name/unit нестрогое — trim и схлопывание пробелов не считаются расхождением', function () {
    $gold = estimatesOf([goldRow(1, ['name' => 'Устройство   ввода'])]);
    $predicted = estimatesOf([goldRow(1, ['name' => '  устройство ввода  '])]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['per_field']['name']['f1'])->toBe(1.0);
});

test('пустой эталон без строк даёт row_coverage=1.0 (нет строк для сверки)', function () {
    $metrics = (new EvalMetrics)->compare(estimatesOf([]), estimatesOf([]));

    expect($metrics['row_coverage'])->toBe(1.0)
        ->and($metrics['overall']['precision'])->toBe(1.0)
        ->and($metrics['overall']['recall'])->toBe(1.0);
});

test('расширенные поля строки P4 (source_position_number/unit_price_base/index_value) участвуют в per_field', function () {
    $gold = estimatesOf([goldRow(1, [
        'source_position_number' => '9.1',
        'unit_price_base' => '100.00',
        'index_value' => '1.15',
    ])]);
    $predicted = estimatesOf([goldRow(1, [
        'source_position_number' => '9.2',
        'unit_price_base' => '150.00',
        'index_value' => '1.30',
    ])]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['per_field']['source_position_number']['f1'])->toBeLessThan(1.0)
        ->and($metrics['per_field']['unit_price_base']['f1'])->toBeLessThan(1.0)
        ->and($metrics['per_field']['index_value']['f1'])->toBeLessThan(1.0);
});

function passportEstimatesOf(array $overrides): array
{
    return estimatesOf([goldRow(1)], $overrides);
}

test('паспорт-уровень: расхождение document_number отражается в metrics.passport, совпадающие поля — нет', function () {
    $gold = passportEstimatesOf(['estimate_number' => 'ЛСР-1', 'document_number' => 'КС-2 №14']);
    $predicted = passportEstimatesOf(['estimate_number' => 'ЛСР-1', 'document_number' => 'КС-2 №15']);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['passport']['document_number']['f1'])->toBeLessThan(1.0)
        ->and($metrics['passport']['estimate_number']['f1'])->toBe(1.0);
});

test('паспорт-уровень: вложенное поле passport.contract_number сравнивается по dot-пути', function () {
    $gold = passportEstimatesOf(['passport' => ['contract_number' => '17-К']]);
    $predicted = passportEstimatesOf(['passport' => ['contract_number' => '18-К']]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['passport']['passport.contract_number']['f1'])->toBeLessThan(1.0);
});

test('паспорт-уровень: labor_hours_workers сравнивается bcmath, не текстом', function () {
    $gold = passportEstimatesOf(['labor_hours_workers' => '120.00']);
    $withinTolerance = passportEstimatesOf(['labor_hours_workers' => '120.005']);
    $mismatch = passportEstimatesOf(['labor_hours_workers' => '150.00']);

    $ok = (new EvalMetrics)->compare($gold, $withinTolerance);
    $bad = (new EvalMetrics)->compare($gold, $mismatch);

    expect($ok['passport']['labor_hours_workers']['f1'])->toBe(1.0)
        ->and($bad['passport']['labor_hours_workers']['f1'])->toBeLessThan(1.0);
});

function totalLine(array $overrides = []): array
{
    return array_merge(['label' => 'Итого', 'value' => '1000.00', 'kind' => 'total'], $overrides);
}

test('итоги: строки footer сопоставляются по (label, kind), расхождение value отражается в metrics.totals', function () {
    $gold = estimatesOf([goldRow(1)], ['totals' => ['footer' => [totalLine(['value' => '1000.00'])]]]);
    $predicted = estimatesOf([goldRow(1)], ['totals' => ['footer' => [totalLine(['value' => '1500.00'])]]]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['totals']['value']['f1'])->toBeLessThan(1.0)
        ->and($metrics['totals']['kind']['f1'])->toBe(1.0);
});

test('итоги: chapter_number/rate участвуют в сравнении сопоставленной строки', function () {
    $gold = estimatesOf([goldRow(1)], ['totals' => ['footer' => [totalLine(['chapter_number' => 5, 'rate' => '18.00'])]]]);
    $predicted = estimatesOf([goldRow(1)], ['totals' => ['footer' => [totalLine(['chapter_number' => 7, 'rate' => '20.00'])]]]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['totals']['chapter_number']['f1'])->toBeLessThan(1.0)
        ->and($metrics['totals']['rate']['f1'])->toBeLessThan(1.0);
});

test('P5.6/D46: три новых поля паспорта (regulatory_base/regulatory_amendments/index_letter) не заходят в PASSPORT_FIELD_MAP — ни один gold-маппер их не заполняет машинно', function () {
    expect(EvalMetrics::PASSPORT_FIELD_MAP)
        ->not->toContain('regulatory_base')
        ->not->toContain('passport.regulatory_amendments')
        ->not->toContain('passport.index_letter');
});

test('расширение EvalMetrics не ломает существующие ключи per_field/overall/row_coverage — passport/totals/quality добавлены отдельно', function () {
    $gold = estimatesOf([goldRow(1)]);
    $predicted = estimatesOf([goldRow(1)]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics)->toHaveKeys(['per_field', 'overall', 'row_coverage', 'passport', 'totals', 'quality'])
        ->and($metrics['overall']['f1'])->toBe(1.0);
});

test('P8.6/D139: пометка неопределённости не заходит ни в FIELD_MAP, ни в ROW_NUMERIC_FIELDS — сторож против тихого включения в F1', function () {
    expect(EvalMetrics::FIELD_MAP)
        ->not->toHaveKey('quality')
        ->not->toContain('quality')
        ->and(EvalMetrics::ROW_NUMERIC_FIELDS)->not->toContain('quality');
});

test('P8.6: заполненность пометок модели считается по позициям предсказания с разбивкой по причинам', function () {
    $gold = estimatesOf([goldRow(1), goldRow(2), goldRow(3), goldRow(4)]);
    $predicted = estimatesOf([
        goldRow(1, ['quantity' => null, 'quality' => ['reason' => 'unreadable', 'fields' => ['quantity']]]),
        goldRow(2),
        goldRow(3),
        goldRow(4),
    ]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['quality'])->toBe([
        'marked_rows' => 1,
        'item_rows' => 4,
        'fill_rate' => 0.25,
        'by_reason' => ['unreadable' => 1],
    ]);
});

test('P8.6/И-А: чистое предсказание без пометок даёт заполненность 0.0 — норма читаемого корпуса', function () {
    $estimates = estimatesOf([goldRow(1), goldRow(2)]);

    $metrics = (new EvalMetrics)->compare($estimates, $estimates);

    expect($metrics['quality']['fill_rate'])->toBe(0.0)
        ->and($metrics['quality']['marked_rows'])->toBe(0)
        ->and($metrics['quality']['item_rows'])->toBe(2);
});

test('P8.6/D148: в разбивке нет ветки провенанса «валидатор» — validator-origin причина не считается пометкой модели', function () {
    $gold = estimatesOf([goldRow(1)]);
    $predicted = estimatesOf([goldRow(1, ['quality' => ['reason' => 'arithmetic_mismatch', 'fields' => ['quantity']]])]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['quality']['by_reason'])->toBe(['unreadable' => 0])
        ->and($metrics['quality']['marked_rows'])->toBe(0);
});

test('P8.6/Г-2: знаменатель заполненности — только строки row_type=item, заголовки и итоги в него не входят', function () {
    $rows = [
        goldRow(1, ['row_type' => 'section_header', 'name' => 'Раздел 1']),
        goldRow(2, ['quality' => ['reason' => 'unreadable', 'fields' => ['total_price_curr']]]),
        goldRow(3, ['row_type' => 'subtotal', 'name' => 'Итого по разделу']),
    ];

    $metrics = (new EvalMetrics)->compare(estimatesOf($rows), estimatesOf($rows));

    expect($metrics['quality']['item_rows'])->toBe(1)
        ->and($metrics['quality']['fill_rate'])->toBe(1.0);
});

test('P8.6: ключ заполненности детерминирован — повторный вызов даёт байт-идентичный результат', function () {
    $gold = estimatesOf([goldRow(1), goldRow(2)]);
    $predicted = estimatesOf([
        goldRow(1, ['quality' => ['reason' => 'unreadable', 'fields' => ['quantity']]]),
        goldRow(2),
    ]);

    $first = json_encode((new EvalMetrics)->compare($gold, $predicted)['quality'], JSON_UNESCAPED_UNICODE);
    $second = json_encode((new EvalMetrics)->compare($gold, $predicted)['quality'], JSON_UNESCAPED_UNICODE);

    expect($first)->toBe($second);
});

/**
 * Смета для многосметных тестов сопоставления (P6.2, D54) — `estimate_number`/`passport.address`
 * настраиваемы, `rows` по умолчанию несёт одну уникальную строку.
 */
function matchableEstimate(array $overrides = []): array
{
    return array_merge([
        'ordinal' => 1,
        'estimate_number' => null,
        'passport' => [],
        'rows' => [goldRow(1)],
    ], $overrides);
}

test('ключевой негативный кейс: матчинг по estimate_number переживает сдвиг на одну смету — обе реальные сметы дают полный recall (findings §3.4 — позиционно расклеивалось)', function () {
    $e1 = matchableEstimate(['estimate_number' => 'ЛСР-1', 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]);
    $e2 = matchableEstimate(['estimate_number' => 'ЛСР-2', 'rows' => [goldRow(1, ['name' => 'Розетка'])]]);
    $hallucinated = matchableEstimate(['estimate_number' => 'ЛСР-0', 'rows' => [goldRow(1, ['name' => 'Придуманная позиция'])]]);

    $gold = [$e1, $e2];
    $predicted = [$hallucinated, $e1, $e2];

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    // recall/row_coverage полные: обе реальные сметы сопоставлены верно вопреки сдвигу индекса;
    // precision снижена ЗАКОНОМЕРНО — лишняя (хаотично вставленная) смета предсказания непарна и
    // целиком FP (Implementation Rule 5), это не дефект пары, а корректный учёт шума.
    expect($metrics['overall']['recall'])->toBe(1.0)
        ->and($metrics['row_coverage'])->toBe(1.0);
});

test('матчинг по estimate_number не зависит от порядка списка (обратный порядок предсказания)', function () {
    $e1 = matchableEstimate(['estimate_number' => 'ЛСР-1', 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]);
    $e2 = matchableEstimate(['estimate_number' => 'ЛСР-2', 'rows' => [goldRow(1, ['name' => 'Розетка'])]]);

    $gold = [$e1, $e2];
    $predicted = [$e2, $e1];

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics['overall']['f1'])->toBe(1.0)
        ->and($metrics['row_coverage'])->toBe(1.0);
});

test('без estimate_number матчинг падает на passport.address', function () {
    $gold = [
        matchableEstimate(['passport' => ['address' => 'г. Москва, ул. Ленина, 1'], 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['passport' => ['address' => 'г. Москва, ул. Мира, 2'], 'rows' => [goldRow(1, ['name' => 'Розетка'])]]),
    ];
    $predicted = [
        matchableEstimate(['passport' => ['address' => 'г. Москва, ул. Мира, 2'], 'rows' => [goldRow(1, ['name' => 'Розетка'])]]),
        matchableEstimate(['passport' => ['address' => 'г. Москва, ул. Ленина, 1'], 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
    ];

    $metrics = (new EvalMetrics)->compare($gold, $predicted);
    $matches = (new EvalMetrics)->matchEstimates($gold, $predicted);

    expect($metrics['overall']['f1'])->toBe(1.0)
        ->and(collect($matches)->pluck('rule')->unique()->all())->toBe(['address']);
});

test('без estimate_number и passport.address матчинг падает на позицию среди оставшихся', function () {
    $gold = [
        matchableEstimate(['rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['rows' => [goldRow(1, ['name' => 'Розетка'])]]),
    ];
    $predicted = [
        matchableEstimate(['rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['rows' => [goldRow(1, ['name' => 'Розетка'])]]),
    ];

    $matches = (new EvalMetrics)->matchEstimates($gold, $predicted);

    expect($matches)->toHaveCount(2)
        ->and($matches[0]['rule'])->toBe('position')
        ->and($matches[1]['rule'])->toBe('position');
});

test('дубль estimate_number на одной стороне неинформативен для всех его носителей — матчинг падает на адрес', function () {
    $gold = [
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'passport' => ['address' => 'Адрес A'], 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'passport' => ['address' => 'Адрес B'], 'rows' => [goldRow(1, ['name' => 'Розетка'])]]),
    ];
    $predicted = [
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'passport' => ['address' => 'Адрес A'], 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'passport' => ['address' => 'Адрес B'], 'rows' => [goldRow(1, ['name' => 'Розетка'])]]),
    ];

    $matches = (new EvalMetrics)->matchEstimates($gold, $predicted);

    expect(collect($matches)->pluck('rule')->unique()->all())->toBe(['address']);
});

test('непарная смета эталона получает собственный слот без пары и считается целиком FN', function () {
    $gold = [
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['estimate_number' => 'ЛСР-2', 'rows' => [goldRow(1, ['name' => 'Розетка'])]]),
    ];
    $predicted = [
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
    ];

    $matches = (new EvalMetrics)->matchEstimates($gold, $predicted);
    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    $unpaired = collect($matches)->first(fn (array $entry): bool => $entry['predicted_index'] === null);

    expect($unpaired)->not->toBeNull()
        ->and($unpaired['rule'])->toBeNull()
        ->and($metrics['overall']['recall'])->toBeLessThan(1.0);
});

test('непарная смета предсказания получает собственный слот без пары и считается целиком FP', function () {
    $gold = [
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
    ];
    $predicted = [
        matchableEstimate(['estimate_number' => 'ЛСР-1', 'rows' => [goldRow(1, ['name' => 'Кабель ВВГ'])]]),
        matchableEstimate(['estimate_number' => 'ЛСР-2', 'rows' => [goldRow(1, ['name' => 'Придуманная позиция'])]]),
    ];

    $matches = (new EvalMetrics)->matchEstimates($gold, $predicted);
    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    $unpaired = collect($matches)->first(fn (array $entry): bool => $entry['gold_index'] === null);

    expect($unpaired)->not->toBeNull()
        ->and($unpaired['rule'])->toBeNull()
        ->and($metrics['overall']['precision'])->toBeLessThan(1.0);
});
