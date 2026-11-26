<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\AnchoredEstimateRow;
use App\Dto\Procurement\Ai\ExtractionArithmeticPolicy;
use App\Dto\Procurement\Ai\ExtractionOutput;
use App\Dto\Procurement\Ai\MoneyDecimal;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\RowAttributes;
use App\Dto\Procurement\Analyzers\Estimate\RowModifier;
use App\Enums\Procurement\Estimate\RowType;
use App\Services\Procurement\Extraction\ExtractionSemanticValidator;

test('проверяет сумму позиции с произведением коэффициентов и отвергает неверную сумму', function (): void {
    $validator = new ExtractionSemanticValidator(
        expected: new RowRange(0, 1),
        estimateOrdinal: 1,
        sourceAnchors: ['p0:r0' => 0],
        arithmetic: new ExtractionArithmeticPolicy('0.00', '0.00'),
    );
    $row = new EstimateRow(
        row_index: 0,
        row_type: RowType::Item,
        name: 'Позиция',
        unit: '1000 м2',
        quantity: '0.00355',
        unit_price_curr: '1854320.50',
        total_price_curr: '7873.07',
        attributes: new RowAttributes(modifiers: [
            new RowModifier(name: 'К=1.15', value: '1.15'),
            new RowModifier(name: 'К=1.04', value: '1.04'),
        ]),
    );
    $output = new ExtractionOutput([new AnchoredEstimateRow(1, 'p0:r0', 0, $row)], []);

    expect($validator->errors($output))->toBe([]);
    $row->total_price_curr = '7788.15';
    expect($validator->errors($output))->toContain('totals_arithmetic');
});

test('округляет общее произведение коэффициентов до семи знаков до расчёта стоимости', function (): void {
    expect(ExtractionSemanticValidator::roundHalfUp('1.1960000', 7))->toBe('1.1960000')
        ->and(ExtractionSemanticValidator::roundHalfUp(bcmul(bcmul('1.15', '1.04', 16), '1.0875', 16), 7))->toBe('1.3006500')
        ->and(ExtractionSemanticValidator::roundHalfUp(bcmul('1.12345678', '1.0', 16), 7))->toBe('1.1234568')
        ->and(ExtractionSemanticValidator::roundHalfUp('-1.235', 2))->toBe('-1.24')
        ->and(ExtractionSemanticValidator::roundHalfUp('1.5', 0))->toBe('2')
        ->and(fn () => ExtractionSemanticValidator::roundHalfUp('1', -1))->toThrow(InvalidArgumentException::class);

    $validator = new ExtractionSemanticValidator(
        expected: new RowRange(0, 1),
        estimateOrdinal: 1,
        sourceAnchors: ['p0:r0' => 0],
        arithmetic: new ExtractionArithmeticPolicy('0.00', '0.00'),
    );
    $row = new EstimateRow(
        row_index: 0,
        row_type: RowType::Item,
        name: 'Позиция',
        unit: 'м2',
        quantity: '1000000',
        unit_price_curr: '1',
        total_price_curr: '1123456.80',
        attributes: new RowAttributes(modifiers: [
            new RowModifier(name: 'К=1.12345678', value: '1.12345678'),
            new RowModifier(name: 'К=1.0', value: '1.0'),
        ]),
    );
    $output = new ExtractionOutput([new AnchoredEstimateRow(1, 'p0:r0', 0, $row)], []);

    expect($validator->errors($output))->toBe([]);
    $row->total_price_curr = '1123456.79';
    expect($validator->errors($output))->toContain('totals_arithmetic');
});

test('переводит тысячи рублей в рубли без потери копеек', function (): void {
    expect(MoneyDecimal::fromThousands('125.45')->numeric())->toBe('125450')
        ->and(MoneyDecimal::fromThousands('-0.01')->numeric())->toBe('-10')
        ->and((new MoneyDecimal('125.45'))->scaleBy('1000')->numeric())->toBe('125450')
        ->and((new MoneyDecimal('1.25'))->scaleBy('0.5')->numeric())->toBe('0.625');
});

test('принимает положительный масштаб денежной единицы и сохраняет конструктор с двумя аргументами', function (): void {
    expect((new ExtractionArithmeticPolicy('0.01', '0.02'))->currencyUnitScale)->toBe('1')
        ->and((new ExtractionArithmeticPolicy('0.01', '0.02', '1000'))->currencyUnitScale)->toBe('1000')
        ->and(fn () => new ExtractionArithmeticPolicy('0.01', '0.02', '0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ExtractionArithmeticPolicy('0.01', '0.02', '-1'))->toThrow(InvalidArgumentException::class);
});
