<?php

declare(strict_types=1);

use App\Dto\Procurement\Gold\GoldFormProfileEstimate;
use App\Dto\Procurement\Gold\GoldHeaderQuote;
use App\Services\Procurement\Gold\FormProfileReader;
use App\Services\Procurement\Gold\GridRecognitionGate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Проверяет пороги распознавания на синтетических книгах PhpSpreadsheet. */
function scenarioAWorkbook(): Spreadsheet
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

/**
 * @return array{sheet: Worksheet, estimate: GoldFormProfileEstimate}
 */
function scenarioA(array $overrides = []): array
{
    $spreadsheet = scenarioAWorkbook();

    $estimateOverrides = array_merge([
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
            'subsection_prefixes' => ['Подраздел '],
            'subtotal_prefixes' => ['Итого прямые затраты', 'Всего по позиции'],
            'summary_prefixes' => ['Итоги по акту:'],
            'ignored_prefixes' => ['Объем='],
            'resource_groups' => [
                ['label' => 'ОТ(ЗТ)', 'resource_type' => 'labor'],
                ['label' => 'ЭМ', 'resource_type' => 'machine'],
            ],
        ],
        'note' => 'синтетический сценарий A',
    ], $overrides);

    $profile = (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLSX-SCENARIO-A',
        'estimates' => [$estimateOverrides],
    ], 'test');

    return ['sheet' => $spreadsheet->getSheetByName('Смета'), 'estimate' => $profile->estimates[0]];
}

test('сценарий A проходит все четыре условия гейта', function () {
    ['sheet' => $sheet, 'estimate' => $estimate] = scenarioA();

    $result = (new GridRecognitionGate)->evaluate($sheet, $estimate);

    expect($result['passed'])->toBeTrue()
        ->and($result['condition_a'])->toBeTrue()
        ->and($result['condition_b'])->toBeTrue()
        ->and($result['condition_c'])->toBeTrue()
        ->and($result['condition_d'])->toBeTrue()
        ->and($result['item_row_count'])->toBe(3)
        ->and($result['uncovered_rows'])->toBe([]);
});

test('цитата, не совпадающая с текстом по адресу, — условие (а) НЕ пройдено', function () {
    ['sheet' => $sheet, 'estimate' => $estimate] = scenarioA([
        'header_cells' => [
            'source_position_number' => [['cell' => 'A5', 'text' => '№ п/п']],
            'code' => [['cell' => 'C5', 'text' => 'Шифр']],
            'name' => [['cell' => 'D5', 'text' => 'Наименование работ и затрат']],
            'unit' => [['cell' => 'F5', 'text' => 'Ед. изм.']],
            'quantity' => [['cell' => 'G5', 'text' => 'Кол-во (НЕ то, что напечатано)'], ['cell' => 'I6', 'text' => 'всего с учетом коэффициентов']],
            'total_price_curr' => [['cell' => 'J5', 'text' => 'Сметная стоимость, руб.'], ['cell' => 'N6', 'text' => 'в текущем уровне цен']],
        ],
    ]);

    $result = (new GridRecognitionGate)->evaluate($sheet, $estimate);

    expect($result['condition_a'])->toBeFalse()
        ->and($result['passed'])->toBeFalse()
        ->and($result['header_mismatches'])->toBe(['quantity']);
});

test('ни одна часть цитаты не лежит в самой колонке поля — условие (а) НЕ пройдено', function () {
    // Хендбилд DTO В ОБХОД FormProfileReader (тот же дефект чтение отклоняет на входе —
    // FormProfileReaderTest это доказывает; здесь проверяется, что и сам гейт защищает
    // инвариант независимо, если профиль пришёл в обход чтения).
    ['sheet' => $sheet, 'estimate' => $validEstimate] = scenarioA();

    $malformed = new GoldFormProfileEstimate(
        ordinal: $validEstimate->ordinal,
        sheet: $validEstimate->sheet,
        header_rows: $validEstimate->header_rows,
        data_row_start: $validEstimate->data_row_start,
        data_row_end: $validEstimate->data_row_end,
        columns: $validEstimate->columns,
        header_cells: array_merge($validEstimate->header_cells, [
            'quantity' => [new GoldHeaderQuote(cell: 'G5', text: 'Количество')],
        ]),
        row_rules: $validEstimate->row_rules,
    );

    $result = (new GridRecognitionGate)->evaluate($sheet, $malformed);

    expect($result['condition_a'])->toBeFalse()
        ->and($result['passed'])->toBeFalse();
});

/**
 * @return array{sheet: Worksheet, estimate: GoldFormProfileEstimate}
 */
function minimalItemsScenario(array $quantities): array
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Смета');
    $sheet->setCellValue('B1', 'Наименование');
    $sheet->setCellValue('C1', 'Количество');

    $row = 2;
    foreach ($quantities as $quantity) {
        $sheet->setCellValue("A{$row}", $row - 1);
        $sheet->setCellValue("B{$row}", "Позиция {$row}");
        $sheet->setCellValue("C{$row}", $quantity);
        $row++;
    }

    $profile = (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLSX-MINIMAL',
        'estimates' => [[
            'ordinal' => 1,
            'sheet' => 'Смета',
            'header_rows' => [1, 1],
            'data_row_start' => 2,
            'data_row_end' => null,
            'columns' => ['name' => 'B', 'quantity' => 'C'],
            'header_cells' => ['name' => [['cell' => 'B1', 'text' => 'Наименование']], 'quantity' => [['cell' => 'C1', 'text' => 'Количество']]],
            'row_rules' => ['item_number_column' => 'A'],
            'note' => 'минимальный сценарий',
        ]],
    ], 'test');

    return ['sheet' => $sheet, 'estimate' => $profile->estimates[0]];
}

test('одна непокрытая строка из двадцати — условие (б) НЕ пройдено (0.95 < 0.98)', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Смета');
    $sheet->setCellValue('B1', 'Наименование');
    $sheet->setCellValue('C1', 'Количество');
    $sheet->setCellValue('B2', 'Незнакомая конструкция формы');

    for ($i = 1; $i <= 19; $i++) {
        $row = $i + 2;
        $sheet->setCellValue("A{$row}", $i);
        $sheet->setCellValue("B{$row}", "Позиция {$row}");
        $sheet->setCellValue("C{$row}", 10);
    }

    $profile = (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLSX-B-CASE',
        'estimates' => [[
            'ordinal' => 1,
            'sheet' => 'Смета',
            'header_rows' => [1, 1],
            'data_row_start' => 2,
            'data_row_end' => null,
            'columns' => ['name' => 'B', 'quantity' => 'C'],
            'header_cells' => ['name' => [['cell' => 'B1', 'text' => 'Наименование']], 'quantity' => [['cell' => 'C1', 'text' => 'Количество']]],
            'row_rules' => ['item_number_column' => 'A'],
        ]],
    ], 'test');

    $result = (new GridRecognitionGate)->evaluate($sheet, $profile->estimates[0]);

    expect($result['role_coverage_ratio'])->toBe(19 / 20)
        ->and($result['condition_b'])->toBeFalse()
        ->and($result['passed'])->toBeFalse();
});

test('текст вместо количества в одной позиции из десяти — условие (в) НЕ пройдено', function () {
    $quantities = array_fill(0, 9, 10);
    $quantities[4] = 'текст';
    $quantities[] = 15;

    ['sheet' => $sheet, 'estimate' => $estimate] = minimalItemsScenario($quantities);

    $result = (new GridRecognitionGate)->evaluate($sheet, $estimate);

    expect($result['item_row_count'])->toBe(10)
        ->and($result['quantity_numeric_ratio'])->toBe(9 / 10)
        ->and($result['condition_c'])->toBeFalse()
        ->and($result['passed'])->toBeFalse();
});

test('две позиции — условие (г) НЕ пройдено', function () {
    ['sheet' => $sheet, 'estimate' => $estimate] = minimalItemsScenario([10, 20]);

    $result = (new GridRecognitionGate)->evaluate($sheet, $estimate);

    expect($result['item_row_count'])->toBe(2)
        ->and($result['condition_d'])->toBeFalse()
        ->and($result['passed'])->toBeFalse();
});

test('справочная доля арифметической сходимости посчитана и НЕ влияет на вердикт', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Смета');
    $sheet->fromArray([['№', 'Наименование', 'Количество', 'Цена', 'Стоимость']], null, 'A1');
    $sheet->fromArray([
        [1, 'П1', 10, 100, 999],
        [2, 'П2', 5, 50, 250],
        [3, 'П3', 2, 20, 40],
    ], null, 'A2');

    $profile = (new FormProfileReader)->fromDecoded([
        'version' => 2,
        'code' => 'EST-XLSX-ARITHMETIC',
        'estimates' => [[
            'ordinal' => 1,
            'sheet' => 'Смета',
            'header_rows' => [1, 1],
            'data_row_start' => 2,
            'data_row_end' => null,
            'columns' => ['name' => 'B', 'quantity' => 'C', 'unit_price_curr' => 'D', 'total_price_curr' => 'E'],
            'header_cells' => [
                'name' => [['cell' => 'B1', 'text' => 'Наименование']],
                'quantity' => [['cell' => 'C1', 'text' => 'Количество']],
                'unit_price_curr' => [['cell' => 'D1', 'text' => 'Цена']],
                'total_price_curr' => [['cell' => 'E1', 'text' => 'Стоимость']],
            ],
            'row_rules' => ['item_number_column' => 'A'],
        ]],
    ], 'test');

    $result = (new GridRecognitionGate)->evaluate($sheet, $profile->estimates[0]);

    expect($result['arithmetic_match_ratio'])->toBe(2 / 3)
        ->and($result['passed'])->toBeTrue();
});
