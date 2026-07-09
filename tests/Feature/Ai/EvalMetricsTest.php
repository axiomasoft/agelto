<?php

declare(strict_types=1);

use App\Services\Procurement\Extraction\EvalMetrics;

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
        'total_price' => '200.00',
    ], $overrides);
}

function estimatesOf(array $rows): array
{
    return [['ordinal' => 1, 'rows' => $rows]];
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
    $gold = estimatesOf([goldRow(1, ['total_price' => '200.00'])]);
    $withinTolerance = estimatesOf([goldRow(1, ['total_price' => '200.005'])]);
    $outsideTolerance = estimatesOf([goldRow(1, ['total_price' => '201.00'])]);

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
