<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Spreadsheet\DeterministicSpreadsheetEstimateParser;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/** @param list<list<null|float|int|string>> $rows */
function estimateSpreadsheet(array $rows, string $extension): string
{
    $base = tempnam(sys_get_temp_dir(), 'estimate_sheet_');

    if ($base === false) {
        throw new RuntimeException('Не удалось создать временный файл.');
    }

    unlink($base);
    $path = $base.'.'.$extension;
    $writer = $extension === 'ods' ? new OdsWriter : new XlsxWriter;
    $writer->openToFile($path);

    try {
        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues($values));
        }
    } finally {
        $writer->close();
    }

    return $path;
}

test('разбирает разделы позиции ресурсы и итоги в двенадцати графах', function (): void {
    $path = estimateSpreadsheet([
        ['Локальная смета'],
        range(1, 12),
        ['Раздел 1', '', 'Земляные работы'],
        [1, 'ФЕР01', 'Разработка грунта', 'м3', '2', '10,00', '20,00'],
        ['Ресурс', 'М-1', 'Песок', 'т', '3', '2', '6'],
        ['Накладные расходы', '', 'Накладные расходы', '', '', '', '5'],
        [2, 'ФЕР02', 'Вывоз грунта', 'м3', '3', '10', '31'],
        ['Итого по разделу', '', 'Итого по разделу', '', '', '', '56'],
        ['Всего по смете', '', 'Всего по смете', '', '', '', '56'],
    ], 'xlsx');

    try {
        $result = (new DeterministicSpreadsheetEstimateParser)->parse($path, 'xlsx');

        expect($result['grid'])->toBe('order_421_12')
            ->and($result['requires_header_fallback'])->toBeFalse()
            ->and($result['sections'])->toHaveCount(1)
            ->and($result['sections'][0]['name'])->toBe('Земляные работы')
            ->and($result['items'])->toHaveCount(2)
            ->and($result['items'][0]['code'])->toBe('ФЕР01')
            ->and($result['items'][0]['quantity'])->toBe('2')
            ->and($result['items'][0]['resources'][0]['name'])->toBe('Песок')
            ->and($result['items'][0]['cost_elements'][0]['name'])->toBe('Накладные расходы')
            ->and($result['items'][1]['section_index'])->toBe(0)
            ->and($result['totals'])->toHaveCount(2)
            ->and($result['totals'][0]['kind'])->toBe('subtotal')
            ->and($result['totals'][1]['kind'])->toBe('summary')
            ->and($result['anomalous_ranges'])->toBe([7]);
    } finally {
        unlink($path);
    }
});

test('разбирает одиннадцать граф МДС в файле ODS', function (): void {
    $path = estimateSpreadsheet([
        ['Локальный сметный расчёт'],
        range(1, 11),
        ['Раздел 1', '', 'Монтаж'],
        [1, 'ТЕР01', 'Монтаж оборудования', 'шт', '2', '15', '30'],
        ['Всего по смете', '', 'Всего по смете', '', '', '', '30'],
    ], 'ods');

    try {
        $result = (new DeterministicSpreadsheetEstimateParser)->parse($path, 'ods');

        expect($result['grid'])->toBe('mds_81_35_11')
            ->and($result['sections'][0]['name'])->toBe('Монтаж')
            ->and($result['items'][0]['amount'])->toBe('30')
            ->and($result['totals'][0]['amount'])->toBe('30')
            ->and($result['anomalous_ranges'])->toBe([]);
    } finally {
        unlink($path);
    }
});

test('распознаёт десять граф ресурсного метода после пустых строк', function (): void {
    $path = estimateSpreadsheet([
        [],
        ['Ресурсная локальная смета'],
        range(1, 10),
        [1, 'ГЭСН01', 'Работа', 'м2', '1', '5', '5'],
    ], 'xlsx');

    try {
        $result = (new DeterministicSpreadsheetEstimateParser)->parse($path, 'xlsx');

        expect($result['grid'])->toBe('order_421_resource_10')
            ->and($result['items'])->toHaveCount(1)
            ->and($result['items'][0]['row'])->toBe(4);
    } finally {
        unlink($path);
    }
});

test('возвращает только сорок строк образца для нестандартной таблицы', function (): void {
    $rows = [];

    for ($index = 1; $index <= 45; $index++) {
        $rows[] = ["Коммерческая строка {$index}"];
    }

    $path = estimateSpreadsheet($rows, 'xlsx');

    try {
        $result = (new DeterministicSpreadsheetEstimateParser)->parse($path, 'xlsx');

        expect($result)->toBe([
            'grid' => null,
            'requires_header_fallback' => true,
            'header_sample_rows' => array_map(fn (array $row): array => $row, array_slice($rows, 0, 40)),
            'sections' => [],
            'items' => [],
            'totals' => [],
            'anomalous_ranges' => [],
        ]);
    } finally {
        unlink($path);
    }
});

test('не считает расхождение ровно в пять копеек аномалией', function (): void {
    $path = estimateSpreadsheet([
        range(1, 12),
        [1, 'ФЕР01', 'Работа', 'шт', '1', '10', '10,05'],
    ], 'xlsx');

    try {
        expect((new DeterministicSpreadsheetEstimateParser)->parse($path, 'xlsx')['anomalous_ranges'])->toBe([]);
    } finally {
        unlink($path);
    }
});

test('не принимает позицию с названием материала за подчинённый ресурс', function (): void {
    $path = estimateSpreadsheet([
        range(1, 12),
        [1, 'ФЕР01', 'Материалы для монтажа', 'шт', '2', '5', '10'],
        ['', 'М-1', 'Кабель', 'м', '3', '1', '3'],
    ], 'xlsx');

    try {
        $result = (new DeterministicSpreadsheetEstimateParser)->parse($path, 'xlsx');

        expect($result['items'])->toHaveCount(1)
            ->and($result['items'][0]['name'])->toBe('Материалы для монтажа')
            ->and($result['items'][0]['resources'][0]['name'])->toBe('Кабель');
    } finally {
        unlink($path);
    }
});

test('отклоняет потоковый путь и повреждённый контейнер XLSX', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'estimate_bad_');

    if ($path === false) {
        throw new RuntimeException('Не удалось создать временный файл.');
    }

    try {
        file_put_contents($path, 'broken');

        expect(fn () => (new DeterministicSpreadsheetEstimateParser)->parse('phar://'.$path, 'xlsx'))->toThrow(RuntimeException::class)
            ->and(fn () => (new DeterministicSpreadsheetEstimateParser)->parse($path, 'xlsx'))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
});
