<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Gold\GoldFormProfile;
use App\Services\Procurement\Gold\FormProfileReader;
use App\Services\Procurement\Gold\GoldValidator;
use App\Services\Procurement\Gold\SheetGoldMapper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Книга + профиль → gold §8.2 (P5.2, D2/D117). Дословность и ничего кроме (Code Guidance):
 * маркер группы ресурсов не попадает в gold и переключает `resource_type` последующих строк;
 * «Всего по позиции» — своя строка `subtotal` со своей стоимостью, стоимость позиции остаётся
 * пустой; `row_index` сквозной по смете с 1 и включает заголовки разделов/итоги; многосметная
 * книга даёт несколько элементов `estimates[]` в порядке `ordinal`; профиль КС-3 кладёт три
 * разреза в `attributes.amount_*` строки `summary_row`. Синтетические книги собираются
 * PhpSpreadsheet'ом В ТЕСТЕ — реальные эталоны корпуса здесь не используются (предмет P5.7).
 */
function mapperFixtureWorkbook(): Spreadsheet
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Смета');

    // Колонки построчно A..N (индекс0..13) — I=индекс8, N=индекс13.
    $sheet->fromArray([
        ['№ п/п', null, 'Шифр', 'Наименование работ и затрат', null, 'Ед. изм.', 'Количество', null, null, 'Сметная стоимость, руб.'],
        [null, null, null, null, null, null, null, null, 'всего с учетом коэффициентов', null, null, null, null, 'в текущем уровне цен'],
        [null, null, null, 'Раздел 1. Земляные работы'],
        [1, null, 'ГЭСН09-03-030-01', 'Разработка грунта', null, 'м3', null, null, 10],
        [null, null, null, 'ОТ(ЗТ)'],
        [null, null, '1-100-36', 'Оплата труда рабочих', null, 'чел.-ч', null, null, 2],
        [null, null, null, 'Всего по позиции', null, null, null, null, null, null, null, null, null, 1100],
        [2, null, 'ГЭСН09-03-031-01', 'Уплотнение грунта', null, 'м3', null, null, 5],
        [null, null, null, 'Всего по позиции', null, null, null, null, null, null, null, null, null, 1000],
        [3, null, 'ГЭСН09-03-032-01', 'Планировка площадки', null, 'м2', null, null, 20],
        [null, null, null, 'Итого прямые затраты', null, null, null, null, null, null, null, null, null, 2100],
    ], null, 'A5');

    return $spreadsheet;
}

function mapperFixtureProfile(): GoldFormProfile
{
    return (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLSX-MAPPER-FIXTURE',
        'estimates' => [[
            'ordinal' => 1,
            'sheet' => 'Смета',
            'header_rows' => [5, 6],
            'data_row_start' => 7,
            'data_row_end' => null,
            'columns' => [
                'source_position_number' => 'A',
                'code' => 'C',
                'name' => 'D',
                'unit' => 'F',
                'quantity' => 'I',
                'total_price_curr' => 'N',
            ],
            'header_cells' => [
                'source_position_number' => [['cell' => 'A5', 'text' => '№ п/п']],
                'code' => [['cell' => 'C5', 'text' => 'Шифр']],
                'name' => [['cell' => 'D5', 'text' => 'Наименование работ и затрат']],
                'unit' => [['cell' => 'F5', 'text' => 'Ед. изм.']],
                'quantity' => [['cell' => 'G5', 'text' => 'Количество'], ['cell' => 'I6', 'text' => 'всего с учетом коэффициентов']],
                'total_price_curr' => [['cell' => 'J5', 'text' => 'Сметная стоимость, руб.'], ['cell' => 'N6', 'text' => 'в текущем уровне цен']],
            ],
            'row_rules' => [
                'item_number_column' => 'A',
                'section_prefixes' => ['Раздел '],
                'subtotal_prefixes' => ['Итого прямые затраты', 'Всего по позиции'],
                'resource_groups' => [
                    ['label' => 'ОТ(ЗТ)', 'resource_type' => 'labor'],
                    ['label' => 'ЭМ', 'resource_type' => 'machine'],
                ],
            ],
            'note' => 'фикстура SheetGoldMapperTest',
        ]],
    ], 'test');
}

test('собранный gold проходит GoldValidator', function () {
    $gold = app(SheetGoldMapper::class)->toGold(mapperFixtureWorkbook(), mapperFixtureProfile());

    expect((new GoldValidator)->validate($gold))->toBe([]);
});

test('строка-маркер группы ресурсов в gold не попадает и переключает resource_type последующих', function () {
    $gold = app(SheetGoldMapper::class)->toGold(mapperFixtureWorkbook(), mapperFixtureProfile());
    $rows = $gold['estimates'][0]['rows'];

    expect(array_column($rows, 'name'))->not->toContain('ОТ(ЗТ)');

    $item1 = collect($rows)->first(fn (array $r): bool => $r['source_position_number'] === '1');

    expect($item1['resources'])->toHaveCount(1)
        ->and($item1['resources'][0]['resource_type'])->toBe('labor')
        ->and($item1['resources'][0]['name'])->toBe('Оплата труда рабочих')
        ->and($item1['resources'][0]['quantity'])->toBe('2');
});

test('строка «Всего по позиции» даёт row_type=subtotal со своей стоимостью, стоимость позиции остаётся пустой', function () {
    $gold = app(SheetGoldMapper::class)->toGold(mapperFixtureWorkbook(), mapperFixtureProfile());
    $rows = $gold['estimates'][0]['rows'];

    $item1 = collect($rows)->first(fn (array $r): bool => $r['source_position_number'] === '1');
    expect($item1['total_price_curr'])->toBeNull();

    $subtotals = collect($rows)->where('row_type', 'subtotal')->values();

    expect($subtotals)->toHaveCount(3)
        ->and($subtotals[0]['name'])->toBe('Всего по позиции')
        ->and($subtotals[0]['total_price_curr'])->toBe('1100')
        ->and($subtotals[1]['total_price_curr'])->toBe('1000')
        ->and($subtotals[2]['name'])->toBe('Итого прямые затраты')
        ->and($subtotals[2]['total_price_curr'])->toBe('2100');
});

test('row_index сквозной по смете с 1 и включает заголовки разделов и итоги', function () {
    $gold = app(SheetGoldMapper::class)->toGold(mapperFixtureWorkbook(), mapperFixtureProfile());
    $rows = $gold['estimates'][0]['rows'];

    expect(array_column($rows, 'row_index'))->toBe(range(1, 7))
        ->and(array_column($rows, 'row_type'))->toBe([
            'section_header', 'item', 'subtotal', 'item', 'subtotal', 'item', 'subtotal',
        ]);
});

test('профиль из двух estimates[] даёт два элемента gold в порядке ordinal', function () {
    $spreadsheet = new Spreadsheet;
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Смета 1');
    $sheet1->fromArray([['№', 'Наименование', 'Количество']], null, 'A1');
    $sheet1->fromArray([[1, 'Позиция 1-1', 5], [2, 'Позиция 1-2', 6]], null, 'A2');

    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Смета 2');
    $sheet2->fromArray([['№', 'Наименование', 'Количество']], null, 'A1');
    $sheet2->fromArray([[1, 'Позиция 2-1', 7]], null, 'A2');

    $estimateProfile = static fn (int $ordinal, string $sheetName): array => [
        'ordinal' => $ordinal,
        'sheet' => $sheetName,
        'header_rows' => [1, 1],
        'data_row_start' => 2,
        'data_row_end' => null,
        'columns' => ['source_position_number' => 'A', 'name' => 'B', 'quantity' => 'C'],
        'header_cells' => [
            'source_position_number' => [['cell' => 'A1', 'text' => '№']],
            'name' => [['cell' => 'B1', 'text' => 'Наименование']],
            'quantity' => [['cell' => 'C1', 'text' => 'Количество']],
        ],
        'row_rules' => ['item_number_column' => 'A'],
    ];

    $profile = (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLSX-MULTI',
        'estimates' => [$estimateProfile(1, 'Смета 1'), $estimateProfile(2, 'Смета 2')],
    ], 'test');

    $gold = app(SheetGoldMapper::class)->toGold($spreadsheet, $profile);

    expect($gold['estimates'])->toHaveCount(2)
        ->and($gold['estimates'][0]['rows'][0]['name'])->toBe('Позиция 1-1')
        ->and($gold['estimates'][0]['rows'])->toHaveCount(2)
        ->and($gold['estimates'][1]['rows'][0]['name'])->toBe('Позиция 2-1')
        ->and($gold['estimates'][1]['rows'])->toHaveCount(1);
});

test('профиль КС-3 кладёт три разреза в attributes.amount_* строки summary_row', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('КС-3');
    $sheet->fromArray([[null, 'Наименование', 'с начала стройки', 'с начала года', 'за период']], null, 'A1');
    $sheet->fromArray([[null, 'Итоги по акту:', 100, 200, 50]], null, 'A2');

    $profile = (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLS-KS3-TEST',
        'estimates' => [[
            'ordinal' => 1,
            'sheet' => 'КС-3',
            'header_rows' => [1, 1],
            'data_row_start' => 2,
            'data_row_end' => null,
            'columns' => [
                'name' => 'B',
                'amount_since_start' => 'C',
                'amount_since_year_start' => 'D',
                'amount_reporting_period' => 'E',
            ],
            'header_cells' => [
                'name' => [['cell' => 'B1', 'text' => 'Наименование']],
                'amount_since_start' => [['cell' => 'C1', 'text' => 'с начала стройки']],
                'amount_since_year_start' => [['cell' => 'D1', 'text' => 'с начала года']],
                'amount_reporting_period' => [['cell' => 'E1', 'text' => 'за период']],
            ],
            'row_rules' => [
                'item_number_column' => 'A',
                'summary_prefixes' => ['Итоги по акту:'],
            ],
        ]],
    ], 'test');

    $gold = app(SheetGoldMapper::class)->toGold($spreadsheet, $profile);
    $row = $gold['estimates'][0]['rows'][0];

    expect($row['row_type'])->toBe('summary_row')
        ->and($row['attributes']['amount_since_start'])->toBe('100')
        ->and($row['attributes']['amount_since_year_start'])->toBe('200')
        ->and($row['attributes']['amount_reporting_period'])->toBe('50');
});
