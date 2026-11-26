<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Spreadsheet;

use App\Services\Procurement\Prepare\XlsxResourceGuard;
use DateTimeInterface;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

final class DeterministicSpreadsheetEstimateParser
{
    /** @return array<string, mixed> */
    public function parse(string $filePath, string $extension): array
    {
        if (str_contains($filePath, '://') || ($realPath = realpath($filePath)) === false || ! is_file($realPath)) {
            throw new RuntimeException('Файл таблицы недоступен или указан через потоковую обёртку.');
        }

        $extension = strtolower($extension);

        if ($extension === 'xlsx' && ! (new XlsxResourceGuard)->accepts($realPath, 'xlsx')) {
            throw new RuntimeException('Небезопасный или повреждённый XLSX-файл.');
        }

        $xlsxOptions = new XlsxOptions;
        $xlsxOptions->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $reader = match ($extension) {
            'xlsx' => new XlsxReader($xlsxOptions),
            'ods' => new OdsReader,
            default => throw new RuntimeException('Неподдерживаемый формат таблицы.'),
        };

        $sections = [];
        $items = [];
        $totals = [];
        $anomalousRanges = [];
        $headerSampleRows = [];
        $grid = null;

        $reader->open($realPath);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetGrid = null;
                $offset = 0;
                $nonEmptyRows = 0;
                $currentSection = null;
                $currentItem = null;

                foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                    $cells = array_map(self::cellText(...), $row->toArray());

                    if (self::isEmpty($cells)) {
                        continue;
                    }

                    if (count($headerSampleRows) < 40) {
                        $headerSampleRows[] = $cells;
                    }

                    if ($sheetGrid === null) {
                        $nonEmptyRows++;
                        $numbering = self::numbering($cells);

                        if ($numbering !== null) {
                            [$sheetGrid, $offset] = $numbering;
                            $grid ??= $sheetGrid;
                        } elseif ($nonEmptyRows >= 80) {
                            break;
                        }

                        continue;
                    }

                    $kind = self::rowKind($cells, $offset, $currentItem !== null);

                    if ($kind === null) {
                        continue;
                    }

                    $name = self::name($cells, $offset);

                    if ($kind === 'section') {
                        $currentSection = count($sections);
                        $currentItem = null;
                        $sections[] = ['sheet' => $sheet->getName(), 'row' => $rowIndex, 'name' => $name];

                        continue;
                    }

                    if ($kind === 'summary' || $kind === 'subtotal') {
                        $totals[] = [
                            'sheet' => $sheet->getName(),
                            'row' => $rowIndex,
                            'kind' => $kind,
                            'name' => $name,
                            'amount' => self::lastNumber($cells),
                            'section_index' => $currentSection,
                        ];

                        continue;
                    }

                    if ($kind === 'resource' || $kind === 'cost_element') {
                        if ($currentItem !== null) {
                            $key = $kind === 'resource' ? 'resources' : 'cost_elements';
                            /** @var list<array<string, mixed>> $children */
                            $children = $items[$currentItem][$key];
                            $children[] = [
                                'row' => $rowIndex,
                                'code' => $cells[$offset + 1] ?? '',
                                'name' => $name,
                                'unit' => $cells[$offset + 3] ?? '',
                                'quantity' => self::number($cells[$offset + 4] ?? ''),
                                'amount' => self::lastNumber($cells),
                            ];
                            $items[$currentItem][$key] = $children;
                        }

                        continue;
                    }

                    $quantity = self::number($cells[$offset + 4] ?? '');
                    $unitPrice = self::number($cells[$offset + 5] ?? '');
                    $amount = self::number($cells[$offset + 6] ?? '');

                    if ($quantity !== null && $unitPrice !== null && $amount !== null) {
                        $difference = bcsub(bcmul($quantity, $unitPrice, 4), $amount, 4);

                        if (bccomp($difference, '0.05', 4) === 1 || bccomp($difference, '-0.05', 4) === -1) {
                            $anomalousRanges[] = $rowIndex;
                        }
                    }

                    $items[] = [
                        'sheet' => $sheet->getName(),
                        'row' => $rowIndex,
                        'section_index' => $currentSection,
                        'num' => $cells[$offset] ?? '',
                        'code' => $cells[$offset + 1] ?? '',
                        'name' => $name,
                        'unit' => $cells[$offset + 3] ?? '',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'amount' => $amount,
                        'resources' => [],
                        'cost_elements' => [],
                    ];
                    $currentItem = array_key_last($items);
                }
            }
        } finally {
            $reader->close();
        }

        if ($grid === null) {
            return [
                'grid' => null,
                'requires_header_fallback' => true,
                'header_sample_rows' => $headerSampleRows,
                'sections' => [],
                'items' => [],
                'totals' => [],
                'anomalous_ranges' => [],
            ];
        }

        return [
            'grid' => $grid,
            'requires_header_fallback' => false,
            'header_sample_rows' => [],
            'sections' => $sections,
            'items' => $items,
            'totals' => $totals,
            'anomalous_ranges' => $anomalousRanges,
        ];
    }

    private static function cellText(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param list<string> $cells */
    private static function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $cells
     * @return array{0: string, 1: int}|null
     */
    private static function numbering(array $cells): ?array
    {
        foreach ([12 => 'order_421_12', 11 => 'mds_81_35_11', 10 => 'order_421_resource_10'] as $width => $grid) {
            for ($start = 0; $start <= count($cells) - $width; $start++) {
                for ($column = 0; $column < $width; $column++) {
                    if (self::number($cells[$start + $column]) !== (string) ($column + 1)) {
                        continue 2;
                    }
                }

                return [$grid, $start];
            }
        }

        return null;
    }

    /** @param list<string> $cells */
    private static function rowKind(array $cells, int $offset, bool $hasItem): ?string
    {
        $label = implode(' ', array_slice($cells, $offset, 4));
        $ordinal = self::number($cells[$offset] ?? '');

        if (preg_match('/(?:итого|всего)\s+по\s+смете|всего\s+с\s+ндс|^\s*ндс\b/iu', $label) === 1) {
            return 'summary';
        }

        if (preg_match('/(?:итого|всего)\s+по\s+разделу|^\s*итого\b/iu', $label) === 1) {
            return 'subtotal';
        }

        if ($ordinal === null && preg_match('/\b(?:раздел|подраздел)\b/iu', $label) === 1) {
            return 'section';
        }

        if (preg_match('/(?:накладные\s+расходы|сметная\s+прибыль|^\s*НР\b|^\s*СП\b)/iu', $label) === 1) {
            return 'cost_element';
        }

        if ($hasItem && $ordinal === null && (preg_match('/\b(?:ресурс|материал|машин|труд)\w*/iu', $label) === 1
            || (($cells[$offset] ?? '') === '' && ($cells[$offset + 2] ?? '') !== ''))) {
            return 'resource';
        }

        if ($ordinal !== null || (($cells[$offset + 1] ?? '') !== '' && ($cells[$offset + 2] ?? '') !== '')) {
            return 'item';
        }

        return null;
    }

    /** @param list<string> $cells */
    private static function name(array $cells, int $offset): string
    {
        $name = $cells[$offset + 2] ?? '';

        if ($name !== '') {
            return $name;
        }

        foreach ($cells as $cell) {
            if ($cell !== '' && self::number($cell) === null) {
                return $cell;
            }
        }

        return '';
    }

    /** @return numeric-string|null */
    private static function number(string $value): ?string
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", ' ', ','], ['', '', '', '.'], trim($value));

        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1 || ! is_numeric($value)) {
            return null;
        }

        return $value;
    }

    /** @param list<string> $cells */
    private static function lastNumber(array $cells): ?string
    {
        foreach (array_reverse($cells) as $cell) {
            $number = self::number($cell);

            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }
}
