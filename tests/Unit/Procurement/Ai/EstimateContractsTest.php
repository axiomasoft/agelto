<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Ai\Estimate\EstimateHeaderMap;
use App\Dto\Procurement\Ai\Estimate\EstimatePassport;
use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Ai\Estimate\TotalsResponse;
use App\Enums\Procurement\DiscoveryMarker;
use App\Enums\Procurement\EstimateTarget;
use App\Enums\Procurement\EstimateType;
use App\Enums\Procurement\IncompleteReason;
use App\Enums\Procurement\ResourceType;
use App\Enums\Procurement\RowType;
use App\Enums\Procurement\TotalKind;

test('гидрирует паспорт сметы из спеки 08 §1.1', function (): void {
    $passport = EstimatePassport::from([
        'type' => 'local_estimate',
        'reference' => 'МДС 81-35.2004',
        'target' => 'construction',
        'address' => 'г. Москва, ул. Ленина, д. 1',
        'generator' => 'Гранд-Смета',
    ]);

    expect($passport->type)->toBe(EstimateType::LocalEstimate)
        ->and($passport->reference)->toBe('МДС 81-35.2004')
        ->and($passport->target)->toBe(EstimateTarget::Construction)
        ->and($passport->address)->toBe('г. Москва, ул. Ленина, д. 1')
        ->and($passport->generator)->toBe('Гранд-Смета');
});

test('гидрирует полный ответ extract_passport из спеки 08 §3.1', function (): void {
    $response = PassportResponse::from([
        'estimate_number' => 'ЛСР-01-01',
        'passport' => [
            'type' => 'act',
            'reference' => null,
            'target' => 'renovation',
            'address' => null,
            'generator' => null,
        ],
        'currency' => 'RUB',
        'base_year' => 2001,
        'price_level' => 'текущие цены на 2026 год',
        'rows_expected' => 42,
        'totals' => [
            'footer' => [
                ['label' => 'Итого', 'value' => '150000.00', 'kind' => null, 'children' => [
                    ['label' => 'в т.ч. НДС 20%', 'value' => '25000.00', 'kind' => 'vat', 'children' => []],
                ]],
            ],
        ],
    ]);

    expect($response->estimate_number)->toBe('ЛСР-01-01')
        ->and($response->passport->type)->toBe(EstimateType::Act)
        ->and($response->passport->target)->toBe(EstimateTarget::Renovation)
        ->and($response->rows_expected)->toBe(42)
        ->and($response->totals?->footer)->toHaveCount(1)
        ->and($response->totals?->footer[0]->label)->toBe('Итого')
        ->and($response->totals?->footer[0]->value)->toBe('150000.00')
        ->and($response->totals?->footer[0]->children)->toHaveCount(1)
        ->and($response->totals?->footer[0]->children[0]->kind)->toBe(TotalKind::Vat);
});

test('гидрирует минимальный ответ extract_passport без итогов', function (): void {
    $response = PassportResponse::from([
        'passport' => [],
    ]);

    expect($response->estimate_number)->toBeNull()
        ->and($response->passport->type)->toBeNull()
        ->and($response->currency)->toBe('RUB')
        ->and($response->rows_expected)->toBe(0)
        ->and($response->totals)->toBeNull();
});

test('гидрирует чанк extract_rows с ресурсами, модификаторами и буфером incomplete_items (спека 08 §3.2)', function (): void {
    $response = RowsChunkResponse::from([
        'rows' => [
            [
                'row_index' => 1,
                'row_type' => 'section_header',
                'name' => 'Раздел 1. Земляные работы',
            ],
            [
                'row_index' => 2,
                'row_type' => 'item',
                'section_title' => 'Раздел 1. Земляные работы',
                'code' => 'ФЕР01-01-001-01',
                'name' => 'Разработка грунта',
                'unit' => 'м3',
                'quantity' => '120.500000',
                'unit_price_curr' => '350.00',
                'total_price_curr' => '42175.00',
                'resources' => [
                    [
                        'resource_type' => 'labor',
                        'name' => 'Затраты труда рабочих',
                        'code' => null,
                        'unit' => 'чел.час',
                        'quantity' => '18.400000',
                        'price_curr' => '210.50',
                    ],
                ],
                'attributes' => [
                    'modifiers' => [
                        ['name' => 'К=1.15', 'kind' => 'winter', 'value' => '1.15'],
                    ],
                ],
            ],
            [
                'row_index' => 3,
                'row_type' => 'subtotal',
                'name' => 'Итого по разделу 1',
                'total_price_curr' => '42175.00',
            ],
        ],
        'incomplete_items' => [
            [
                'reason' => 'split_by_page_end',
                'raw_text' => 'ФЕР01-01-002-01 Устройство корыта...',
                'last_known_code' => 'ФЕР01-01-002-01',
                'page_number' => 4,
            ],
        ],
    ]);

    expect($response->rows)->toHaveCount(3)
        ->and($response->rows[0]->row_type)->toBe(RowType::SectionHeader)
        ->and($response->rows[1]->row_type)->toBe(RowType::Item)
        ->and($response->rows[1]->resources[0]->resource_type)->toBe(ResourceType::Labor)
        ->and($response->rows[1]->attributes?->modifiers[0]->value)->toBe('1.15')
        ->and($response->rows[2]->row_type)->toBe(RowType::Subtotal)
        ->and($response->incomplete_items)->toHaveCount(1)
        ->and($response->incomplete_items[0]->reason)->toBe(IncompleteReason::SplitByPageEnd)
        ->and($response->incomplete_items[0]->page_number)->toBe(4);
});

test('гидрирует пустой чанк extract_rows без строк', function (): void {
    $response = RowsChunkResponse::from([]);

    expect($response->rows)->toBe([])
        ->and($response->incomplete_items)->toBe([]);
});

test('RowsChunkResponse::structuralError() ловит дубликат row_index', function (): void {
    $response = RowsChunkResponse::from([
        'rows' => [
            ['row_index' => 1, 'row_type' => 'item', 'name' => 'Позиция 1'],
            ['row_index' => 1, 'row_type' => 'item', 'name' => 'Позиция 1 дубль'],
        ],
    ]);

    expect($response->structuralError())->toContain('Дубликат row_index');
});

test('RowsChunkResponse::structuralError() ловит немонотонный row_index', function (): void {
    $response = RowsChunkResponse::from([
        'rows' => [
            ['row_index' => 5, 'row_type' => 'item', 'name' => 'Позиция 5'],
            ['row_index' => 3, 'row_type' => 'item', 'name' => 'Позиция 3'],
        ],
    ]);

    expect($response->structuralError())->toContain('монотонность');
});

test('RowsChunkResponse::structuralError() возвращает null для валидного построчного диапазона', function (): void {
    $response = RowsChunkResponse::from([
        'rows' => [
            ['row_index' => 1, 'row_type' => 'section_header', 'name' => 'Раздел 1'],
            ['row_index' => 2, 'row_type' => 'item', 'name' => 'Позиция 1'],
            ['row_index' => 3, 'row_type' => 'subtotal', 'name' => 'Итого по разделу'],
        ],
    ]);

    expect($response->structuralError())->toBeNull();
});

test('гидрирует ответ extract_totals (спека 08 §3.3)', function (): void {
    $response = TotalsResponse::from([
        'totals' => [
            'footer' => [
                ['label' => 'Всего по смете', 'value' => '1000000.00', 'kind' => null, 'children' => []],
            ],
        ],
    ]);

    expect($response->totals->footer)->toHaveCount(1)
        ->and($response->totals->footer[0]->label)->toBe('Всего по смете');
});

test('гидрирует ответ discovery-прохода с маркерами границ смет (спека 08 §3.4)', function (): void {
    $response = DiscoveryResponse::from([
        'markers' => [
            ['page' => 1, 'marker' => 'estimate_start', 'title' => 'Смета №1 на кровельные работы'],
            ['page' => 1, 'marker' => 'continuation'],
            ['page' => 4, 'marker' => 'estimate_end'],
        ],
    ]);

    expect($response->markers)->toHaveCount(3)
        ->and($response->markers[0]->marker)->toBe(DiscoveryMarker::EstimateStart)
        ->and($response->markers[0]->title)->toBe('Смета №1 на кровельные работы')
        ->and($response->markers[1]->marker)->toBe(DiscoveryMarker::Continuation)
        ->and($response->markers[2]->marker)->toBe(DiscoveryMarker::EstimateEnd);
});

test('гидрирует словарь синонимов заголовков таблицы позиций (спека 08 §2.1)', function (): void {
    $map = EstimateHeaderMap::from([
        'row_index' => ['index' => 0, 'name' => '№ п/п', 'full_name' => '№ п/п'],
        'code' => ['index' => 1, 'name' => 'Шифр', 'full_name' => 'Шифр расценки'],
        'name' => ['index' => 2, 'name' => 'Наименование работ', 'full_name' => 'Наименование работ и затрат'],
        'unit' => null,
        'quantity' => ['index' => 4, 'name' => 'Количество', 'full_name' => 'Количество'],
        'total_price' => ['index' => 5, 'name' => 'Стоимость всего', 'full_name' => 'Стоимость всего, руб.'],
    ]);

    expect($map->row_index?->name)->toBe('№ п/п')
        ->and($map->code?->full_name)->toBe('Шифр расценки')
        ->and($map->unit)->toBeNull()
        ->and($map->total_price?->index)->toBe(5);
});
