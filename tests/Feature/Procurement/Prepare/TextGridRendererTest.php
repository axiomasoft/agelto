<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Tools\TextGridRenderer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

/**
 * Формат `text_grid`: строка = `<row_index>\t<ячейка>…`, лист = `## SHEET: <имя>`,
 * merge развёрнут в повтор, пустые строки/колонки опущены без сдвига `row_index`. Синтетические
 * книги собираются PhpSpreadsheet'ом в самом тесте (Code Guidance).
 */
function buildXlsx(callable $fill): string
{
    $spreadsheet = new Spreadsheet;
    $fill($spreadsheet);

    $path = tempnam(sys_get_temp_dir(), 'grid_test_').'.xlsx';
    (new XlsxWriter($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

test('точка входа обработчика сохраняет объединённые ячейки и отклоняет превышение бюджета', function (): void {
    $path = buildXlsx(function (Spreadsheet $spreadsheet): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Смета');
        $sheet->setCellValue('A1', 'Раздел');
        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A3', 2);
        $sheet->setCellValue('B3', '=A3*3');
    });
    $output = tempnam(sys_get_temp_dir(), 'grid_result_');
    expect($output)->not->toBeFalse();

    try {
        expect(TextGridRenderer::runSubprocess($path, 'xlsx', 10, 100, $output))->toBe(0);
        $result = json_decode(file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        expect($result['sheets'])->toBe(1)
            ->and($result['rows'])->toBe(2)
            ->and($result['text'])->toContain('## SHEET: Смета', "1\tРаздел\tРаздел", "3\t2\t6");
        expect(TextGridRenderer::runSubprocess($path, 'xlsx', 10, 1, $output))->toBe(1);
    } finally {
        unlink($path);
        unlink($output);
    }
});

test('горизонтальный merge развёрнут в повтор значения по всем ячейкам диапазона', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Раздел 1');
        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A2', 'a');
        $sheet->setCellValue('B2', 'b');
        $sheet->setCellValue('C2', 'c');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($result)->not->toBeFalse()
        ->and($result['text'])->toContain("1\tРаздел 1\tРаздел 1\tРаздел 1")
        ->and($result['text'])->toContain("2\ta\tb\tc");
});

test('полностью пустая строка опущена, row_index следующей строки не сдвинулся', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'первая');
        // строка 2 остаётся полностью пустой
        $sheet->setCellValue('A3', 'третья');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');
    $lines = explode("\n", $result['text']);

    expect($lines)->toContain("1\tпервая")
        ->and($lines)->toContain("3\tтретья")
        ->and(collect($lines)->filter(fn ($line) => str_starts_with($line, '2'.chr(9))))->toBeEmpty();
});

test('формула отдала вычисленное значение, а не исходную запись', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 2);
        $sheet->setCellValue('B1', 3);
        $sheet->setCellValue('C1', '=A1+B1');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($result['text'])->toContain("1\t2\t3\t5");
});

test('многолистовая книга даёт две секции ## SHEET', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $spreadsheet->getActiveSheet()->setTitle('Смета');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'x');
        $second = $spreadsheet->createSheet();
        $second->setTitle('Ресурсы');
        $second->setCellValue('A1', 'y');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($result['text'])->toContain('## SHEET: Смета')
        ->and($result['text'])->toContain('## SHEET: Ресурсы')
        ->and($result['sheets'])->toBe(2);
});

test('книга сверх лимита инструмента — false, не приговор документу', function () {
    config(['ai.prepare.xlsx_max_rows' => 1]);
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'первая');
        $sheet->setCellValue('A2', 'вторая');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($result)->toBeFalse();
});

test('повторный прогон идемпотентен — один и тот же текст на входе даёт один и тот же результат', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'значение');
    });

    $first = app(TextGridRenderer::class)->render($path, 'xlsx');
    $second = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($first)->toBe($second);
});

test('полностью пустая колонка опущена', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'a');
        // колонка B остаётся полностью пустой
        $sheet->setCellValue('C1', 'c');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($result['text'])->toContain("1\ta\tc")
        ->and($result['text'])->not->toContain("1\ta\t\tc");
});

test('регрессия разреженности — далёкая непустая ячейка не заставляет обход материализовать пустой used-range', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'первая');
        $sheet->setCellValue('IW9000', 'далёкая');
    });

    $originalLimit = ini_get('memory_limit');
    // Полный suite уже держит память предыдущих тестов. Даём renderer фиксированные 128 MiB
    // ДОПОЛНИТЕЛЬНОГО headroom, иначе абсолютный 128M делает тест зависимым от порядка файлов.
    $headroomBytes = memory_get_usage(true) + 128 * 1024 * 1024;
    ini_set('memory_limit', (string) $headroomBytes);

    try {
        $result = app(TextGridRenderer::class)->render($path, 'xlsx');
    } finally {
        ini_set('memory_limit', $originalLimit);
    }

    $lines = explode("\n", $result['text']);

    expect($result)->not->toBeFalse()
        ->and($lines)->toContain("1\tпервая\t")
        ->and($lines)->toContain("9000\t\tдалёкая");
});

test('непустая неякорная ячейка внутри merge при пустом якоре рендерится пустой', function () {
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('B1', 'скрытое');
        $sheet->mergeCells('A1:C1');
        $sheet->setCellValue('A2', 'a');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');
    $lines = explode("\n", $result['text']);

    expect($result['text'])->not->toContain('скрытое')
        ->and(collect($lines)->filter(fn ($line) => str_starts_with($line, '1'.chr(9))))->toBeEmpty()
        ->and($lines)->toContain('2'.chr(9).'a');
});

test('превышение бюджета ячеек — false, не исключение', function () {
    config(['ai.prepare.xlsx_max_cells' => 1]);
    $path = buildXlsx(function (Spreadsheet $spreadsheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'первая');
        $sheet->setCellValue('A2', 'вторая');
    });

    $result = app(TextGridRenderer::class)->render($path, 'xlsx');

    expect($result)->toBeFalse();
});
