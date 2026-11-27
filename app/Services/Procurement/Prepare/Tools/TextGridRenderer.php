<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Services\Procurement\Gold\WorksheetCellReader;
use App\Support\Config\TypedConfig;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Компактная текстовая сетка таблицы — третий кандидат входа в LLM наряду с PDF и
 * OCR-текстом. Формат ФИКСИРУЕТСЯ здесь и не меняется без пересмотра решения (бенчмарк сравнивает
 * представления, а не варианты одного представления):
 *
 * - строка = `<row_index>\t<ячейка>\t<ячейка>…`, где `row_index` — 1-based номер строки ЛИСТА
 *   (тот же якорь, что в контракте извлечения, `docs/specs/v2/08-estimate-extraction.md` §3.1);
 * - лист начинается строкой `## SHEET: <имя листа>`;
 * - горизонтально/вертикально объединённая ячейка ПОВТОРЯЕТСЯ во всех ячейках диапазона merge
 *   (модель не видит merge, а пустоты ломают выравнивание шапки);
 * - полностью пустые строки и колонки ОПУСКАЮТСЯ, но `row_index` при этом НЕ пересчитывается —
 *   якорь обязан совпадать с реальным номером строки листа, иначе сравнение с gold несопоставимо;
 * - числа — вычисленное значение БЕЗ форматирования Excel (`getCalculatedValue`, не
 *   `getFormattedValue` — формат ячейки не несёт данных, а токены тратит).
 *
 * Нормализация заголовков/словари синонимов сюда НЕ переносятся — рендер отдаёт всё, что есть на
 * листе, дело промта/извлечения решать, где заголовок.
 *
 * Size-gate — ТОТ ЖЕ, что у PDF-ветки `SpreadsheetStrategy` (`ai.prepare.xlsx_max_mb`/
 * `xlsx_max_rows`): превышение лимита ИНСТРУМЕНТА возвращает `false`, а не бросает —
 * у текстовой сетки нет второго инструмента-фолбэка, документ при этом НЕ приговаривается
 * (решение принимает вызывающая стратегия).
 *
 * `setReadDataOnly(true)` здесь НЕ используется: PhpSpreadsheet Xlsx-ридер при `readDataOnly=true` полностью
 * пропускает парсинг `<mergeCells>` (`Reader/Xlsx.php:1101`), а горизонтальный merge — явное
 * обязательное поведение представления. Size-gate это не
 * ослабляет: он считается ДО загрузки (`filesize` + `listWorksheetInfo`), а не за счёт
 * пропуска стилей при самой загрузке.
 *
 * Обход листа — РАЗРЕЖЕННЫЙ: `Worksheet::getCell` СОЗДАЁТ отсутствующую ячейку
 * (`Worksheet.php:1185-1202`), поэтому плотный проход `1..highestColumn × 1..highestRow`
 * материализует пустой used-range целиком — на легаси `.xls` с раздутыми границами это разрыв
 * 84× (2 487 503 создаваемых объекта против 29 464 реально существующих) и детерминированный OOM. Вместо этого: (1) карта
 * значений строится ТОЛЬКО по существующим координатам (`getCellCollection->getCoordinates`);
 * (2) merge накладывается ПОСЛЕ сбора — якорь переписывает значением все координаты диапазона,
 * а ПУСТОЙ якорь удаляет их из карты (сохраняет сегодняшнее поведение: непустая неякорная ячейка
 * внутри merge рендерится пустой); (3) непустые колонки/строки выводятся из этой же карты.
 * Возвращаться к `cellExists`-скану по прямоугольнику ЗАПРЕЩЕНО — он снимает создание ячеек, но
 * оставляет квадратичное число итераций впустую. Бюджет ячеек (`ai.prepare.xlsx_max_cells`)
 * считается ДО сбора карты по сумме существующих координат и площади merge-диапазонов —
 * превышение возвращает `false` тем же контрактом «лимит инструмента», что и `xlsx_max_mb`/
 * `xlsx_max_rows`.
 */
final readonly class TextGridRenderer
{
    private const int MAX_OUTPUT_BYTES = 8 * 1024 * 1024;

    public function __construct(private XlsxResourceGuard $xlsxResourceGuard) {}

    /**
     * @return array{text: string, sheets: int, rows: int}|false
     */
    public function render(string $localPath, string $extension, ?float $deadlineAt = null): array|false
    {
        if (! in_array($extension, ['xls', 'xlsx'], true) || str_contains($localPath, '://')) {
            return false;
        }

        $realPath = realpath($localPath);

        if ($realPath === false || ! is_file($realPath)) {
            return false;
        }

        $maxMb = TypedConfig::positiveFloat('ai.prepare.xlsx_max_mb', 25.0);
        $fileSize = @filesize($realPath);
        if (! is_int($fileSize) || $fileSize > $maxMb * 1024 * 1024
            || ! $this->xlsxResourceGuard->accepts(path: $realPath, extension: $extension)) {
            return false;
        }

        $maxRows = TypedConfig::positiveInt('ai.prepare.xlsx_max_rows', 20_000);
        $maxCells = TypedConfig::positiveInt('ai.prepare.xlsx_max_cells', 2_000_000);
        $memoryLimit = $this->memoryLimitConfig(key: 'ai.prepare.xlsx_convert_memory_limit', default: '512M');
        $timeout = TypedConfig::positiveInt('ai.prepare.xlsx_convert_timeout_seconds', 120);
        $remaining = $deadlineAt === null ? $timeout : (int) floor($deadlineAt - microtime(true));
        if ($remaining < 1) {
            return false;
        }
        $outputPath = PipelineTemporaryFile::create(prefix: 'text_grid_', extension: 'json');

        try {
            $script = 'require $argv[1]; exit(\App\Services\Procurement\Prepare\Tools\TextGridRenderer::runSubprocess($argv[2], $argv[3], (int) $argv[4], (int) $argv[5], $argv[6]));';
            $result = Process::path(base_path())
                ->timeout(min($timeout, $remaining))
                ->quietly()
                ->run([
                    PHP_BINARY,
                    '-d',
                    "memory_limit={$memoryLimit}",
                    '-r',
                    $script,
                    '--',
                    base_path('vendor/autoload.php'),
                    $realPath,
                    $extension,
                    (string) $maxRows,
                    (string) $maxCells,
                    $outputPath,
                ]);
            if ($result->failed() || is_link($outputPath) || ! is_file($outputPath)) {
                return false;
            }
            $rawOutput = @file_get_contents(filename: $outputPath, length: self::MAX_OUTPUT_BYTES + 1);
            if ($rawOutput === false || strlen($rawOutput) > self::MAX_OUTPUT_BYTES) {
                return false;
            }
        } catch (ProcessTimedOutException) {
            return false;
        } finally {
            @unlink($outputPath);
        }

        $decoded = json_decode($rawOutput, true);
        if (! is_array($decoded)
            || ! isset($decoded['text'], $decoded['sheets'], $decoded['rows'])
            || ! is_string($decoded['text'])
            || ! is_int($decoded['sheets'])
            || ! is_int($decoded['rows'])) {
            return false;
        }

        return [
            'text' => $decoded['text'],
            'sheets' => $decoded['sheets'],
            'rows' => $decoded['rows'],
        ];
    }

    public static function runSubprocess(string $localPath, string $extension, int $maxRows, int $maxCells, string $outputPath): int
    {
        $renderer = new self(new XlsxResourceGuard);
        $rendered = $renderer->renderInProcess(
            localPath: $localPath,
            extension: $extension,
            maxRows: $maxRows,
            maxCells: $maxCells,
        );

        if ($rendered === false) {
            return 1;
        }

        $json = json_encode($rendered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_OUTPUT_BYTES) {
            return 1;
        }

        return @file_put_contents($outputPath, $json) === strlen($json) ? 0 : 1;
    }

    /**
     * @return array{text: string, sheets: int, rows: int}|false
     */
    private function renderInProcess(string $localPath, string $extension, int $maxRows, int $maxCells): array|false
    {
        if (str_contains($localPath, '://')) {
            return false;
        }

        $realPath = realpath($localPath);

        if ($realPath === false || ! is_file($realPath) || $this->countRows($realPath, $extension) > $maxRows) {
            return false;
        }

        $reader = $this->createReader($extension, $maxRows, $maxCells);

        try {
            $spreadsheet = $reader->load($realPath);
        } catch (\Throwable) {
            return false;
        }

        try {
            $sheets = $spreadsheet->getAllSheets();

            if ($this->cellBudget($sheets) > $maxCells) {
                return false;
            }

            $lines = [];
            $totalRows = 0;

            foreach ($sheets as $sheet) {
                $this->renderSheet($sheet, $lines, $totalRows);
            }

            return [
                'text' => implode("\n", $lines),
                'sheets' => count($sheets),
                'rows' => $totalRows,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    private function createReader(string $extension, int $maxRows, int $maxCells): XlsReader|XlsxReader
    {
        $reader = $extension === 'xls' ? new XlsReader : new XlsxReader;
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class($maxRows, $maxCells) implements IReadFilter
        {
            private int $visitedCells = 0;

            public function __construct(
                private readonly int $maxRows,
                private readonly int $maxCells,
            ) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                if ($row > $this->maxRows) {
                    throw new \OverflowException('Row limit exceeded.');
                }

                if (++$this->visitedCells > $this->maxCells) {
                    throw new \OverflowException('Cell budget exceeded.');
                }

                return true;
            }
        });

        return $reader;
    }

    /**
     * @param  list<string>  $lines
     */
    private function renderSheet(Worksheet $sheet, array &$lines, int &$totalRows): void
    {
        $lines[] = '## SHEET: '.$sheet->getTitle();

        $values = $this->collectValues($sheet);
        $this->applyMerges($sheet->getMergeCells(), $values);
        ksort($values, SORT_NUMERIC);

        $columns = $this->distinctColumns($values);

        foreach ($values as $row => $rowValues) {
            if ($rowValues === []) {
                continue;
            }

            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $rowValues[$column] ?? '';
            }

            $lines[] = $row."\t".implode("\t", $cells);
            $totalRows++;
        }
    }

    /**
     * @param  array<Worksheet>  $sheets
     */
    private function cellBudget(array $sheets): int
    {
        $total = 0;

        foreach ($sheets as $sheet) {
            $total += count($sheet->getCellCollection()->getCoordinates());
            $total += $this->mergeArea($sheet->getMergeCells());
        }

        return $total;
    }

    /**
     * @param  array<array-key, string>  $mergeRanges
     */
    private function mergeArea(array $mergeRanges): int
    {
        $area = 0;

        foreach ($mergeRanges as $range) {
            [[$startColumn, $startRow], [$endColumn, $endRow]] = Coordinate::rangeBoundaries($range);
            $area += ($endColumn - $startColumn + 1) * ($endRow - $startRow + 1);
        }

        return $area;
    }

    /**
     * @return array<int, array<int, string>> карта значений `[row][column] => value`, только
     *                                        существующие непустые ячейки
     */
    private function collectValues(Worksheet $sheet): array
    {
        $values = [];

        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            [$column, $row] = Coordinate::indexesFromString($coordinate);
            $cell = $sheet->getCell($coordinate);

            try {
                $rawValue = $cell->getCalculatedValue();
            } catch (\Throwable) {
                $rawValue = $cell->getValue();
            }

            $value = WorksheetCellReader::stringify($rawValue);

            if ($value === '') {
                continue;
            }

            $values[$row][$column] = $value;
        }

        return $values;
    }

    /**
     * @param  array<array-key, string>  $mergeRanges
     * @param  array<int, array<int, string>>  $values
     */
    private function applyMerges(array $mergeRanges, array &$values): void
    {
        foreach ($mergeRanges as $range) {
            [[$startColumn, $startRow], [$endColumn, $endRow]] = Coordinate::rangeBoundaries($range);
            $anchor = $values[$startRow][$startColumn] ?? '';

            for ($row = $startRow; $row <= $endRow; $row++) {
                for ($column = $startColumn; $column <= $endColumn; $column++) {
                    if ($anchor === '') {
                        unset($values[$row][$column]);

                        continue;
                    }

                    $values[$row][$column] = $anchor;
                }
            }
        }
    }

    /**
     * @param  array<int, array<int, string>>  $values
     * @return array<int, int> отсортированные по возрастанию номера непустых колонок
     */
    private function distinctColumns(array $values): array
    {
        $columns = [];

        foreach ($values as $rowValues) {
            foreach (array_keys($rowValues) as $column) {
                $columns[$column] = true;
            }
        }

        ksort($columns, SORT_NUMERIC);

        return array_keys($columns);
    }

    private function countRows(string $path, string $extension): int
    {
        $reader = $extension === 'xls' ? new XlsReader : new XlsxReader;

        try {
            $info = $reader->listWorksheetInfo($path);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }

        return array_sum(array_column($info, 'totalRows'));
    }

    private function memoryLimitConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        if (! is_string($value) || preg_match('/^[1-9][0-9]*[KMG]?$/iD', $value) !== 1 || ini_parse_quantity($value) < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive PHP memory limit.");
        }

        return $value;
    }
}
