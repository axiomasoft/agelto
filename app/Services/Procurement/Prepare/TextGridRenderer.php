<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Компактная текстовая сетка таблицы (P3.5, D3) — третий кандидат входа в LLM наряду с PDF и
 * OCR-текстом. Формат ФИКСИРУЕТСЯ здесь и не меняется без пересмотра решения (P6 сравнивает
 * представления, а не варианты одного представления):
 *
 * - строка = `<row_index>\t<ячейка>\t<ячейка>…`, где `row_index` — 1-based номер строки ЛИСТА
 *   (тот же якорь, что в контракте извлечения, `docs/specs/v2/08-estimate-extraction.md` §3.1);
 * - лист начинается строкой `## SHEET: <имя листа>`;
 * - горизонтально/вертикально объединённая ячейка ПОВТОРЯЕТСЯ во всех ячейках диапазона merge
 *   (модель не видит merge, а пустоты ломают выравнивание шапки);
 * - полностью пустые строки и колонки ОПУСКАЮТСЯ, но `row_index` при этом НЕ пересчитывается —
 *   якорь обязан совпадать с реальным номером строки листа, иначе сравнение с gold несопоставимо;
 * - числа — вычисленное значение БЕЗ форматирования Excel (`getCalculatedValue()`, не
 *   `getFormattedValue()` — формат ячейки не несёт данных, а токены тратит).
 *
 * Нормализация заголовков/словари синонимов сюда НЕ переносятся — рендер отдаёт всё, что есть на
 * листе, дело промта/извлечения (P7) решать, где заголовок.
 *
 * Size-gate — ТОТ ЖЕ, что у PDF-ветки `SpreadsheetStrategy` (`ai.prepare.xlsx_max_mb`/
 * `xlsx_max_rows`, D26): превышение лимита ИНСТРУМЕНТА возвращает `false`, а не бросает —
 * у текстовой сетки нет второго инструмента-фолбэка, документ при этом НЕ приговаривается
 * (решение принимает вызывающая стратегия).
 *
 * `setReadDataOnly(true)` здесь НЕ используется (отклонение от буквы Implementation Rules,
 * см. Known Deviations P3.5): PhpSpreadsheet Xlsx-ридер при `readDataOnly=true` полностью
 * пропускает парсинг `<mergeCells>` (`Reader/Xlsx.php:1101`), а горизонтальный merge — явное
 * обязательное поведение представления (Implementation Rules + тест-кейс). Size-gate это не
 * ослабляет: он считается ДО загрузки (`filesize()` + `listWorksheetInfo()`), а не за счёт
 * пропуска стилей при самой загрузке.
 *
 * Обход листа — РАЗРЕЖЕННЫЙ (P2.8, D95): `Worksheet::getCell()` СОЗДАЁТ отсутствующую ячейку
 * (`Worksheet.php:1185-1202`), поэтому плотный проход `1..highestColumn × 1..highestRow`
 * материализует пустой used-range целиком — на легаси `.xls` с раздутыми границами это разрыв
 * 84× (2 487 503 создаваемых объекта против 29 464 реально существующих,
 * `findings/P2-textgrid-oom-2026-08-01.md` §2) и детерминированный OOM. Вместо этого: (1) карта
 * значений строится ТОЛЬКО по существующим координатам (`getCellCollection()->getCoordinates()`);
 * (2) merge накладывается ПОСЛЕ сбора — якорь переписывает значением все координаты диапазона,
 * а ПУСТОЙ якорь удаляет их из карты (сохраняет сегодняшнее поведение: непустая неякорная ячейка
 * внутри merge рендерится пустой); (3) непустые колонки/строки выводятся из этой же карты.
 * Возвращаться к `cellExists()`-скану по прямоугольнику ЗАПРЕЩЕНО — он снимает создание ячеек, но
 * оставляет квадратичное число итераций впустую. Бюджет ячеек (`ai.prepare.xlsx_max_cells`)
 * считается ДО сбора карты по сумме существующих координат и площади merge-диапазонов —
 * превышение возвращает `false` тем же контрактом «лимит инструмента», что и `xlsx_max_mb`/
 * `xlsx_max_rows`.
 */
final readonly class TextGridRenderer
{
    /**
     * @return array{text: string, sheets: int, rows: int}|false
     */
    public function render(string $localPath, string $extension): array|false
    {
        $maxMb = $this->positiveFloatConfig(key: 'ai.prepare.xlsx_max_mb', default: 25.0);
        if (filesize($localPath) > $maxMb * 1024 * 1024) {
            return false;
        }

        $maxRows = $this->positiveIntConfig(key: 'ai.prepare.xlsx_max_rows', default: 20_000);
        if ($this->countRows($localPath, $extension) > $maxRows) {
            return false;
        }

        $reader = $extension === 'xls' ? new XlsReader : new XlsxReader;
        $spreadsheet = $reader->load($localPath);
        $sheets = $spreadsheet->getAllSheets();

        $maxCells = $this->positiveIntConfig(key: 'ai.prepare.xlsx_max_cells', default: 2_000_000);
        if ($this->cellBudget($sheets) > $maxCells) {
            $spreadsheet->disconnectWorksheets();

            return false;
        }

        $lines = [];
        $totalRows = 0;

        foreach ($sheets as $sheet) {
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

        $spreadsheet->disconnectWorksheets();

        return [
            'text' => implode("\n", $lines),
            'sheets' => count($sheets),
            'rows' => $totalRows,
        ];
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
            $value = $this->stringify($sheet->getCell($coordinate)->getCalculatedValue());

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
        $info = $reader->listWorksheetInfo($path);

        return array_sum(array_column($info, 'totalRows'));
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if (is_int($value) || is_string($value) || $value instanceof \Stringable) {
            return trim((string) $value);
        }

        return '';
    }

    private function positiveFloatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        if ((! is_float($value) && ! is_int($value)) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive number.");
        }

        return (float) $value;
    }

    private function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
