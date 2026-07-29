<?php

declare(strict_types=1);

use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;

/**
 * §8.4 `docs/specs/v2/08-estimate-extraction.md` — field-level precision/recall/F1 + row coverage
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

test('расширение EvalMetrics не ломает существующие ключи per_field/overall/row_coverage — passport/totals добавлены отдельно', function () {
    $gold = estimatesOf([goldRow(1)]);
    $predicted = estimatesOf([goldRow(1)]);

    $metrics = (new EvalMetrics)->compare($gold, $predicted);

    expect($metrics)->toHaveKeys(['per_field', 'overall', 'row_coverage', 'passport', 'totals'])
        ->and($metrics['overall']['f1'])->toBe(1.0);
});
