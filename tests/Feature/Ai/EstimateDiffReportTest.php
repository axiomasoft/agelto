<?php

declare(strict_types=1);

use App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport;
use App\Services\Procurement\Analyzers\Estimate\EstimateValidator;
use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;

/**
 * §8.6 `docs/specs/v2/08-estimate-extraction.md` — детерминированный diff-отчёт «gold vs
 * предсказание» (`EstimateDiffReport::build()`, P7.3, D62), без LLM (чистая функция над формой
 * §8.2/`analysis.result.estimates[]`).
 */
function diffRow(int $rowIndex, array $overrides = []): array
{
    return array_merge([
        'row_index' => $rowIndex,
        'row_type' => 'item',
        'name' => 'Устройство ввода',
        'total_price_curr' => '200.00',
    ], $overrides);
}

function diffEstimate(array $rows, array $overrides = []): array
{
    return array_merge(['ordinal' => 1, 'rows' => $rows], $overrides);
}

function diffReport(): EstimateDiffReport
{
    return new EstimateDiffReport(new EvalMetrics, new EstimateValidator);
}

test('идентичные gold и предсказание дают пустые списки расхождений и verdict «расхождений нет»', function () {
    $estimates = [diffEstimate([diffRow(1)])];

    $report = diffReport()->build($estimates, $estimates);

    expect($report->rows)->toBe([])
        ->and($report->passport)->toBe([])
        ->and($report->totals)->toBe([])
        ->and($report->verdict)->toBe('расхождений нет')
        ->and($report->truncated)->toBe(['shown' => 0, 'total' => 0])
        ->and($report->summary)->not->toBeEmpty();
});

test('пропущенная строка предсказания даёт запись missing с обоими адресами', function () {
    $gold = [diffEstimate([diffRow(1), diffRow(2)])];
    $predicted = [diffEstimate([diffRow(1)])];

    $report = diffReport()->build($gold, $predicted);

    expect($report->rows)->toHaveCount(1);

    $entry = $report->rows[0]->toArray();

    expect($entry['kind'])->toBe('missing')
        ->and($entry['estimate_index'])->toBe(0)
        ->and($entry['row_index'])->toBe(2)
        ->and($entry['gold'])->not->toBeNull()
        ->and($entry['predicted'])->toBeNull();
});

test('лишняя (галлюцинированная) строка предсказания даёт запись extra', function () {
    $gold = [diffEstimate([diffRow(1)])];
    $predicted = [diffEstimate([diffRow(1), diffRow(2, ['name' => 'Придуманная позиция'])])];

    $report = diffReport()->build($gold, $predicted);

    expect($report->rows)->toHaveCount(1);

    $entry = $report->rows[0]->toArray();

    expect($entry['kind'])->toBe('extra')
        ->and($entry['row_index'])->toBe(2)
        ->and($entry['gold'])->toBeNull()
        ->and($entry['predicted'])->not->toBeNull();
});

test('разное значение поля даёт запись field_mismatch с обоими значениями', function () {
    $gold = [diffEstimate([diffRow(1, ['name' => 'Кабель ВВГ'])])];
    $predicted = [diffEstimate([diffRow(1, ['name' => 'Кабель ВВГнг'])])];

    $report = diffReport()->build($gold, $predicted);

    $mismatch = collect($report->rows)->first(fn ($entry) => $entry->field === 'name');

    expect($mismatch)->not->toBeNull()
        ->and($mismatch->kind)->toBe('field_mismatch')
        ->and($mismatch->gold)->toBe('Кабель ВВГ')
        ->and($mismatch->predicted)->toBe('Кабель ВВГнг');
});

test('арифметика считается раздельно по gold и по предсказанию — расхождение одной стороны не красит другую', function () {
    $section = [
        diffRow(1, ['row_type' => 'section_header']),
        diffRow(2),
    ];

    $gold = [diffEstimate([...$section, diffRow(3, ['row_type' => 'subtotal', 'total_price_curr' => '200.00'])])];
    $predicted = [diffEstimate([...$section, diffRow(3, ['row_type' => 'subtotal', 'total_price_curr' => '999.00'])])];

    $report = diffReport()->build($gold, $predicted);

    expect($report->arithmetic)->toHaveCount(1)
        ->and($report->arithmetic[0]['gold']['status'])->toBe('valid')
        ->and($report->arithmetic[0]['predicted']['status'])->toBe('suspicious')
        ->and($report->arithmetic[0]['predicted']['notes'])->not->toBe([])
        ->and($report->verdict)->not->toBe('расхождений нет');
});

test('усечение списка расхождений выставляет truncated.shown/total и не теряет summary', function () {
    $gold = [diffEstimate([diffRow(1), diffRow(2), diffRow(3)])];
    $predicted = [diffEstimate([])];

    $report = diffReport()->build($gold, $predicted, limitRows: 1);

    expect($report->rows)->toHaveCount(1)
        ->and($report->truncated)->toBe(['shown' => 1, 'total' => 3])
        ->and($report->summary)->not->toBeEmpty();
});

test('повторный вызов на тех же входах даёт байт-идентичный JSON', function () {
    $gold = [diffEstimate([diffRow(1), diffRow(2, ['name' => 'Кабель ВВГ'])])];
    $predicted = [diffEstimate([diffRow(1), diffRow(2, ['name' => 'Иное наименование'])])];

    $first = json_encode(diffReport()->build($gold, $predicted)->toArray(), JSON_UNESCAPED_UNICODE);
    $second = json_encode(diffReport()->build($gold, $predicted)->toArray(), JSON_UNESCAPED_UNICODE);

    expect($first)->toBe($second);
});

test('паспорт-уровень: расхождение document_number даёт запись field_mismatch без row_index', function () {
    $gold = [diffEstimate([diffRow(1)], ['document_number' => 'КС-2 №14'])];
    $predicted = [diffEstimate([diffRow(1)], ['document_number' => 'КС-2 №15'])];

    $report = diffReport()->build($gold, $predicted);

    expect($report->passport)->toHaveCount(1);

    $entry = $report->passport[0]->toArray();

    expect($entry['kind'])->toBe('field_mismatch')
        ->and($entry['field'])->toBe('document_number')
        ->and($entry['row_index'])->toBeNull();
});

test('итоги: расхождение value сопоставленной строки footer даёт запись field_mismatch', function () {
    $gold = [diffEstimate([diffRow(1)], ['totals' => ['footer' => [['label' => 'Итого', 'value' => '1000.00', 'kind' => 'total']]]])];
    $predicted = [diffEstimate([diffRow(1)], ['totals' => ['footer' => [['label' => 'Итого', 'value' => '1500.00', 'kind' => 'total']]]])];

    $report = diffReport()->build($gold, $predicted);

    $mismatch = collect($report->totals)->first(fn ($entry) => str_ends_with((string) $entry->field, '.value'));

    expect($mismatch)->not->toBeNull()
        ->and($mismatch->gold)->toBe('1000.00')
        ->and($mismatch->predicted)->toBe('1500.00');
});
