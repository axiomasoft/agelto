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
 */
final readonly class TextGridRenderer
{
    /**
     * @return array{text: string, sheets: int, rows: int}|false
     */
    public function render(string $localPath, string $extension): array|false
    {
        $maxMb = (float) config('ai.prepare.xlsx_max_mb', 25);
        if (filesize($localPath) > $maxMb * 1024 * 1024) {
            return false;
        }

        $maxRows = (int) config('ai.prepare.xlsx_max_rows', 20000);
        if ($this->countRows($localPath, $extension) > $maxRows) {
            return false;
        }

        $reader = $extension === 'xls' ? new XlsReader : new XlsxReader;
        $spreadsheet = $reader->load($localPath);

        $lines = [];
        $totalRows = 0;
        $sheets = $spreadsheet->getAllSheets();

        foreach ($sheets as $sheet) {
            $lines[] = '## SHEET: '.$sheet->getTitle();

            $highestRow = $sheet->getHighestRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $mergeMap = $this->buildMergeMap($sheet->getMergeCells());
            $emptyColumns = $this->emptyColumns($sheet, $highestRow, $highestColumn, $mergeMap);

            for ($row = 1; $row <= $highestRow; $row++) {
                $cells = [];
                $rowHasValue = false;

                for ($column = 1; $column <= $highestColumn; $column++) {
                    if (isset($emptyColumns[$column])) {
                        continue;
                    }

                    $value = $this->cellText($sheet, $column, $row, $mergeMap);

                    if ($value !== '') {
                        $rowHasValue = true;
                    }

                    $cells[] = $value;
                }

                if (! $rowHasValue) {
                    continue;
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
     * @return array<string, string> ключ "колонка:строка" → значение якорной ячейки merge-диапазона
     */
    private function buildMergeMap(array $mergeRanges): array
    {
        $map = [];

        foreach ($mergeRanges as $range) {
            [[$startColumn, $startRow], [$endColumn, $endRow]] = Coordinate::rangeBoundaries($range);

            for ($row = $startRow; $row <= $endRow; $row++) {
                for ($column = $startColumn; $column <= $endColumn; $column++) {
                    $map["{$column}:{$row}"] = "{$startColumn}:{$startRow}";
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $mergeMap
     */
    private function cellText(Worksheet $sheet, int $column, int $row, array $mergeMap): string
    {
        $key = "{$column}:{$row}";
        [$anchorColumn, $anchorRow] = isset($mergeMap[$key])
            ? array_map('intval', explode(':', $mergeMap[$key]))
            : [$column, $row];

        $value = $sheet->getCell([$anchorColumn, $anchorRow])->getCalculatedValue();

        return $this->stringify($value);
    }

    /**
     * @param  array<string, string>  $mergeMap
     * @return array<int, true>
     */
    private function emptyColumns(Worksheet $sheet, int $highestRow, int $highestColumn, array $mergeMap): array
    {
        $empty = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $hasValue = false;

            for ($row = 1; $row <= $highestRow; $row++) {
                if ($this->cellText($sheet, $column, $row, $mergeMap) !== '') {
                    $hasValue = true;
                    break;
                }
            }

            if (! $hasValue) {
                $empty[$column] = true;
            }
        }

        return $empty;
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

        return trim((string) $value);
    }
}
