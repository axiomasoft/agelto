<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Ai\Estimate\TotalLine;
use App\Enums\Procurement\EstimateValidationStatus;
use App\Enums\Procurement\RowType;
use App\Services\Procurement\Analyzers\Estimate\EstimateValidator;

function validatorRow(
    int $index,
    RowType $type = RowType::Item,
    ?string $totalPrice = null,
): EstimateRow {
    return new EstimateRow(
        row_index: $index,
        row_type: $type,
        name: "Строка {$index}",
        total_price_curr: $totalPrice,
    );
}

test('validate возвращает valid когда полнота и арифметика совпадают', function () {
    $rows = [
        validatorRow(1, RowType::SectionHeader),
        validatorRow(2, totalPrice: '100.00'),
        validatorRow(3, totalPrice: '50.00'),
        validatorRow(4, RowType::Subtotal, totalPrice: '150.00'),
    ];

    $report = app(EstimateValidator::class)->validate(rowsExpected: 2, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Valid)
        ->and($report->notes)->toBe([]);
});

test('validate помечает suspicious при небольшом расхождении rows_expected/извлечено (≤10%)', function () {
    $rows = array_map(static fn (int $i): EstimateRow => validatorRow($i, totalPrice: '10.00'), range(1, 19));

    $report = app(EstimateValidator::class)->validate(rowsExpected: 20, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Suspicious)
        ->and($report->notes)->not->toBe([]);
});

test('validate помечает invalid при серьёзной потере позиций (>10% расхождение)', function () {
    $rows = array_map(static fn (int $i): EstimateRow => validatorRow($i, totalPrice: '10.00'), range(1, 5));

    $report = app(EstimateValidator::class)->validate(rowsExpected: 20, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Invalid);
});

test('validate пропускает проверку полноты когда rows_expected=0 (оконный путь §4а)', function () {
    $rows = [validatorRow(1, totalPrice: '10.00')];

    $report = app(EstimateValidator::class)->validate(rowsExpected: 0, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Valid);
});

test('validate находит расхождение суммы позиций раздела с subtotal-строкой (±0.01 допуск)', function () {
    $rows = [
        validatorRow(1, totalPrice: '100.00'),
        validatorRow(2, totalPrice: '50.00'),
        validatorRow(3, RowType::Subtotal, totalPrice: '999.00'),
    ];

    $report = app(EstimateValidator::class)->validate(rowsExpected: 2, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Suspicious)
        ->and($report->anomalousRowIndexes)->toContain(1, 2, 3);
});

test('validate принимает расхождение в пределах допуска ±0.01 как совпадение', function () {
    $rows = [
        validatorRow(1, totalPrice: '100.001'),
        validatorRow(2, RowType::Subtotal, totalPrice: '100.00'),
    ];

    $report = app(EstimateValidator::class)->validate(rowsExpected: 1, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Valid);
});

test('validate обнаруживает разрыв нумерации row_index', function () {
    $rows = [validatorRow(1, totalPrice: '10.00'), validatorRow(5, totalPrice: '10.00')];

    $report = app(EstimateValidator::class)->validate(rowsExpected: 0, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Suspicious)
        ->and($report->anomalousRowIndexes)->toContain(5);
});

test('validate best-effort сверяет сумму позиций с итоговой строкой footer по метке «Итого»', function () {
    $rows = [validatorRow(1, totalPrice: '100.00'), validatorRow(2, totalPrice: '50.00')];
    $totals = new EstimateTotalsBlock(footer: [
        new TotalLine(label: 'Итого', value: '150.00'),
    ]);

    $report = app(EstimateValidator::class)->validate(rowsExpected: 2, rows: $rows, totals: $totals);

    expect($report->status)->toBe(EstimateValidationStatus::Valid);
});

test('validate флагирует расхождение с итоговой строкой footer как suspicious', function () {
    $rows = [validatorRow(1, totalPrice: '100.00')];
    $totals = new EstimateTotalsBlock(footer: [
        new TotalLine(label: 'Всего по смете', value: '999.00'),
    ]);

    $report = app(EstimateValidator::class)->validate(rowsExpected: 1, rows: $rows, totals: $totals);

    expect($report->status)->toBe(EstimateValidationStatus::Suspicious)
        ->and($report->notes)->toContain('итог: сумма позиций 100.00 ≠ «Всего по смете» 999.00 (best-effort сверка)');
});
