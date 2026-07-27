<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Estimate\EstimateRow;
use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Enums\Procurement\CalcMethod;
use App\Enums\Procurement\RowType;
use Illuminate\Validation\ValidationException;

// Контракты расширенные P4.5 по утверждённому распределению величин
// (research/06-schema-decision.md §8) — четыре обязательных кейса из Code Guidance P4.5.

test('новое поле утверждённого списка валидируется (work_type_name, index_code, source_position_number)', function (): void {
    $row = EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Устройство кровли',
        'code' => 'ФЕР12-01-001-01',
        'quantity' => '10.000000',
        'index_code' => 'ИПР-69',
        'index_value' => '7.237366',
        'source_position_number' => '9.1',
        'work_type_name' => 'Кровельные работы',
    ]);

    expect($row->index_code)->toBe('ИПР-69')
        ->and($row->index_value)->toBe('7.237366')
        ->and($row->source_position_number)->toBe('9.1')
        ->and($row->work_type_name)->toBe('Кровельные работы');
});

test('decimal-величины приходят строкой и не теряют точность', function (): void {
    $row = EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'unit_price_base' => '17260.412345',
        'unit_price_curr' => '236122.370000',
        'total_price_base' => '91063.160000',
        'total_price_curr' => '1050000.010000',
    ]);

    expect($row->unit_price_base)->toBe('17260.412345')
        ->and($row->unit_price_curr)->toBe('236122.370000')
        ->and($row->total_price_base)->toBe('91063.160000')
        ->and($row->total_price_curr)->toBe('1050000.010000');
});

test('неизвестный ключ attributes отклоняется', function (): void {
    EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Позиция',
        'attributes' => [
            'modifiers' => [],
            'unexpected_key' => 'значение вне утверждённой схемы',
        ],
    ]);
})->throws(ValidationException::class);

test('известные ключи attributes (modifiers + КС-3-разрезы) проходят валидацию', function (): void {
    $row = EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'summary_row',
        'name' => 'Свод по акту',
        'attributes' => [
            'modifiers' => [
                ['name' => 'К=1.15', 'kind' => 'winter', 'value' => '1.15'],
            ],
            'amount_since_start' => '150000.00',
            'amount_since_year_start' => '90000.00',
            'amount_reporting_period' => '30000.00',
        ],
    ]);

    expect($row->attributes?->modifiers)->toHaveCount(1)
        ->and($row->attributes?->modifiers[0]->value)->toBe('1.15')
        ->and($row->attributes?->amount_since_start)->toBe('150000.00')
        ->and($row->row_type)->toBe(RowType::SummaryRow);
});

test('пример §8.2 проходит validateAndCreate() целиком (паспорт + строки)', function (): void {
    $passport = PassportResponse::validateAndCreate([
        'estimate_number' => 'ЛСР-01-01',
        'passport' => [
            'type' => 'estimate',
            'reference' => null,
            'target' => 'construction',
            'address' => null,
            'generator' => 'Гранд-Смета',
            'contract_number' => '17-СМ-0042',
            'contract_date' => '2026-01-15',
        ],
        'currency' => 'RUB',
        'base_year' => 2001,
        'price_level' => 'ФЕР-2001',
        'rows_expected' => 2,
        'calc_method' => 'base_index',
        'labor_hours_workers' => '120.500000',
        'labor_hours_machinists' => '18.250000',
        'document_number' => 'КС-2-17',
        'document_date' => '2026-01-20',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'totals' => [
            'footer' => [
                ['label' => 'Итого', 'value' => '150000.00', 'kind' => 'total', 'children' => []],
                ['label' => 'Дополнительные затраты по главе 5', 'value' => null, 'kind' => 'additional', 'chapter_number' => 5, 'rate' => '2.500000', 'children' => []],
            ],
        ],
    ]);

    expect($passport->calc_method)->toBe(CalcMethod::BaseIndex)
        ->and($passport->passport->contract_number)->toBe('17-СМ-0042')
        ->and($passport->totals?->footer[1]->chapter_number)->toBe(5)
        ->and($passport->totals?->footer[1]->value)->toBeNull();

    $row = EstimateRow::validateAndCreate([
        'row_index' => 1,
        'row_type' => 'item',
        'name' => 'Устройство кровли',
        'code' => 'ФЕР12-01-001-01',
        'unit' => 'м2',
        'quantity' => '120.500000',
        'unit_price_base' => '350.00',
        'unit_price_curr' => '412.30',
        'total_price_base' => '42175.00',
        'total_price_curr' => '49682.15',
        'index_code' => 'ИПР-69',
        'index_value' => '1.178000',
        'source_position_number' => '9.1',
        'work_type_name' => 'Кровельные работы',
        'resources' => [
            [
                'resource_type' => 'labor',
                'name' => 'Затраты труда рабочих',
                'unit' => 'чел.час',
                'quantity' => '18.400000',
                'price_base' => '180.00',
                'price_curr' => '210.50',
                'index_code' => 'ИПР-69',
                'index_value' => '1.178000',
            ],
        ],
        'attributes' => [
            'modifiers' => [
                ['name' => 'К=1.15', 'kind' => 'winter', 'value' => '1.15'],
            ],
        ],
    ]);

    expect($row->resources[0]->price_curr)->toBe('210.50')
        ->and($row->attributes?->modifiers[0]->name)->toBe('К=1.15');
});
