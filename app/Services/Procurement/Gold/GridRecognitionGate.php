<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Gold\GoldExplicitRow;
use App\Dto\Procurement\Gold\GoldFormProfileColumns;
use App\Dto\Procurement\Gold\GoldFormProfileEstimate;
use App\Dto\Procurement\Gold\GoldResourceGroup;
use App\Enums\Procurement\Estimate\ResourceType;
use App\Enums\Procurement\Estimate\RowType;
use App\Support\Numbers\DecimalNormalizer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Проверка распознаваемости книги по профилю. Требует четыре условия:
 * (а) дословная привязка 100% объявленных колонок; (б) покрытие ролей строк не ниже 0.98;
 * (в) числовая состоятельность quantity и объявленной стоимостной колонки не ниже 0.95;
 * (г) минимум три строки item. Неуспех возвращает downgrade для ручного решения.
 *
 * classifyRows() — общий классификатор ролей для gate и SheetGoldMapper. FormProfileReader
 * проверяет структуру профиля без книги; совпадение цитат с содержимым ячеек проверяется здесь.
 */
final readonly class GridRecognitionGate
{
    private const float ROLE_COVERAGE_THRESHOLD = 0.98;

    private const float NUMERIC_RATIO_THRESHOLD = 0.95;

    private const int MIN_ITEM_ROWS = 3;

    private const array COST_COLUMN_FIELDS = [
        'unit_price_base', 'unit_price_curr', 'total_price_base', 'total_price_curr',
    ];

    /**
     * @return array{
     *     passed: bool, condition_a: bool, condition_b: bool, condition_c: bool, condition_d: bool,
     *     header_match_ratio: float, header_mismatches: list<string>,
     *     role_coverage_ratio: float, uncovered_rows: list<int>,
     *     quantity_numeric_ratio: float, subtotal_numeric_ratio: float|null,
     *     item_row_count: int, arithmetic_match_ratio: float|null,
     * }
     */
    public function evaluate(Worksheet $sheet, GoldFormProfileEstimate $estimate): array
    {
        $header = $this->verifyHeaderBinding($sheet, $estimate);
        $rows = $this->classifyRows($sheet, $estimate);

        $itemRows = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === RowType::Item->value));
        $subtotalRows = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === RowType::Subtotal->value));
        $uncoveredRows = array_values(array_filter($rows, static fn (array $r): bool => $r['role'] === 'uncovered'));

        $totalNonEmpty = count($rows);
        $roleCoverageRatio = $totalNonEmpty > 0 ? (count($rows) - count($uncoveredRows)) / $totalNonEmpty : 0.0;

        $columns = WorksheetCellReader::declaredColumnIndexes($estimate->columns);
        $quantityNumericRatio = $this->numericRatio($sheet, $columns, $itemRows, 'quantity');
        $hasCostColumn = $this->hasCostColumn($estimate->columns);
        $subtotalNumericRatio = $hasCostColumn
            ? $this->costNumericRatio($sheet, $columns, $subtotalRows)
            : null;

        $conditionA = $header['ratio'] >= 1.0;
        $conditionB = $roleCoverageRatio >= self::ROLE_COVERAGE_THRESHOLD;
        $conditionC = $quantityNumericRatio >= self::NUMERIC_RATIO_THRESHOLD
            && ($subtotalNumericRatio === null || $subtotalNumericRatio >= self::NUMERIC_RATIO_THRESHOLD);
        $conditionD = count($itemRows) >= self::MIN_ITEM_ROWS;

        return [
            'passed' => $conditionA && $conditionB && $conditionC && $conditionD,
            'condition_a' => $conditionA,
            'condition_b' => $conditionB,
            'condition_c' => $conditionC,
            'condition_d' => $conditionD,
            'header_match_ratio' => $header['ratio'],
            'header_mismatches' => $header['mismatches'],
            'role_coverage_ratio' => $roleCoverageRatio,
            'uncovered_rows' => array_column($uncoveredRows, 'row'),
            'quantity_numeric_ratio' => $quantityNumericRatio,
            'subtotal_numeric_ratio' => $subtotalNumericRatio,
            'item_row_count' => count($itemRows),
            'arithmetic_match_ratio' => $this->arithmeticMatchRatio($sheet, $columns, $itemRows),
        ];
    }

    /**
     * Роль КАЖДОЙ непустой строки диапазона `data_row_start…data_row_end` по замкнутому набору
     * правил (порядок — сверху вниз, первое сработавшее побеждает):
     * 1) `explicit_rows` по номеру строки; 2) `ignored_prefixes` в колонке `name`; 3) `section_
     * prefixes`/`subsection_prefixes` — первая непустая ячейка в пределах колонок профиля; 4)
     * `summary_prefixes` в колонке `name`; 5) `subtotal_prefixes` в колонке `name`; 6) `resource_
     * groups[].label` — точное совпадение колонки `name` (маркер, в gold не попадает, переключает
     * текущий тип ресурса); 7) непустая ячейка `item_number_column` → `item`; 8) прочая непустая
     * строка ПОСЛЕ хотя бы одной позиции → ресурс текущего типа (`other`, если не переключён ни
     * разу); 9) всё остальное — `uncovered`. Полностью пустая строка (по колонкам профиля) в
     * список НЕ попадает вовсе — не входит ни в числитель, ни в знаменатель условия (б).
     *
     * @return list<array{row: int, role: string, name: string, resource_type: ?ResourceType}>
     */
    public function classifyRows(Worksheet $sheet, GoldFormProfileEstimate $estimate): array
    {
        $columns = WorksheetCellReader::declaredColumnIndexes($estimate->columns);
        $sortedColumns = $columns;
        asort($sortedColumns);

        $explicitByRow = [];
        foreach ($estimate->row_rules->explicit_rows as $explicit) {
            $explicitByRow[$explicit->row] = $explicit;
        }

        $itemNumberColumnIndex = Coordinate::columnIndexFromString($estimate->row_rules->item_number_column);
        $dataRowEnd = $estimate->data_row_end ?? $sheet->getHighestRow();

        $result = [];
        $currentResourceType = null;
        $sawItem = false;

        for ($row = $estimate->data_row_start; $row <= $dataRowEnd; $row++) {
            $cells = $this->rowCellTexts($sheet, $columns, $row);
            if ($cells === []) {
                continue;
            }

            $result[] = $this->classifyRow(
                sheet: $sheet,
                estimate: $estimate,
                row: $row,
                cells: $cells,
                sortedColumns: $sortedColumns,
                explicitByRow: $explicitByRow,
                itemNumberColumnIndex: $itemNumberColumnIndex,
                currentResourceType: $currentResourceType,
                sawItem: $sawItem,
            );
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, int>  $sortedColumns
     * @param  array<int, GoldExplicitRow>  $explicitByRow
     * @return array{row: int, role: string, name: string, resource_type: ?ResourceType}
     */
    private function classifyRow(
        Worksheet $sheet,
        GoldFormProfileEstimate $estimate,
        int $row,
        array $cells,
        array $sortedColumns,
        array $explicitByRow,
        int $itemNumberColumnIndex,
        ?ResourceType &$currentResourceType,
        bool &$sawItem,
    ): array {
        $nameText = $cells['name'] ?? '';

        if (isset($explicitByRow[$row])) {
            $explicit = $explicitByRow[$row];
            if ($explicit->role === RowType::Item->value) {
                $sawItem = true;
            }

            return ['row' => $row, 'role' => $explicit->role, 'name' => $explicit->text, 'resource_type' => null];
        }

        if ($this->matchesAnyPrefix($nameText, $estimate->row_rules->ignored_prefixes)) {
            return ['row' => $row, 'role' => 'ignored', 'name' => $nameText, 'resource_type' => null];
        }

        $firstNonEmptyText = $this->firstNonEmptyText($cells, $sortedColumns);

        if ($this->matchesAnyPrefix($firstNonEmptyText, $estimate->row_rules->section_prefixes)) {
            return ['row' => $row, 'role' => RowType::SectionHeader->value, 'name' => $firstNonEmptyText, 'resource_type' => null];
        }

        if ($this->matchesAnyPrefix($firstNonEmptyText, $estimate->row_rules->subsection_prefixes)) {
            return ['row' => $row, 'role' => RowType::SubsectionHeader->value, 'name' => $firstNonEmptyText, 'resource_type' => null];
        }

        if ($this->matchesAnyPrefix($nameText, $estimate->row_rules->summary_prefixes)) {
            return ['row' => $row, 'role' => RowType::SummaryRow->value, 'name' => $nameText, 'resource_type' => null];
        }

        if ($this->matchesAnyPrefix($nameText, $estimate->row_rules->subtotal_prefixes)) {
            return ['row' => $row, 'role' => RowType::Subtotal->value, 'name' => $nameText, 'resource_type' => null];
        }

        $matchedGroup = $this->matchResourceGroup($nameText, $estimate->row_rules->resource_groups);
        if ($matchedGroup !== null) {
            $currentResourceType = $matchedGroup->resource_type;

            return ['row' => $row, 'role' => 'resource_marker', 'name' => $nameText, 'resource_type' => $currentResourceType];
        }

        if (WorksheetCellReader::cellText($sheet, $itemNumberColumnIndex, $row) !== '') {
            $sawItem = true;

            return ['row' => $row, 'role' => RowType::Item->value, 'name' => $nameText, 'resource_type' => null];
        }

        if ($sawItem) {
            return [
                'row' => $row,
                'role' => 'resource',
                'name' => $nameText,
                'resource_type' => $currentResourceType ?? ResourceType::Other,
            ];
        }

        return ['row' => $row, 'role' => 'uncovered', 'name' => $nameText, 'resource_type' => null];
    }

    /**
     * Нормализует числа: удаляет разрядные пробелы и NBSP, заменяет запятую точкой;
     * прочерк и пустота дают null. Вычисления используют bcmath без float.
     * SheetGoldMapper применяет ту же нормализацию к значениям gold.
     */
    /** @return numeric-string|null */
    public static function cleanNumeric(?string $raw): ?string
    {
        return DecimalNormalizer::normalize($raw);
    }

    /**
     * @return array{ratio: float, mismatches: list<string>}
     */
    private function verifyHeaderBinding(Worksheet $sheet, GoldFormProfileEstimate $estimate): array
    {
        $declared = WorksheetCellReader::declaredColumnIndexes($estimate->columns);
        $mismatches = [];
        $verified = 0;

        foreach ($declared as $field => $columnIndex) {
            $quotes = $estimate->header_cells[$field] ?? [];
            $hasOwnColumnPart = false;
            $allMatch = $quotes !== [];

            foreach ($quotes as $quote) {
                [$quoteColumn, $quoteRow] = Coordinate::coordinateFromString($quote->cell);
                $quoteColumnIndex = Coordinate::columnIndexFromString($quoteColumn);

                if ($quoteColumnIndex === $columnIndex) {
                    $hasOwnColumnPart = true;
                }

                $actual = $this->normalizeHeaderText(WorksheetCellReader::cellText($sheet, $quoteColumnIndex, (int) $quoteRow));
                $expected = $this->normalizeHeaderText($quote->text);

                if ($actual !== $expected) {
                    $allMatch = false;
                }
            }

            if ($allMatch && $hasOwnColumnPart) {
                $verified++;
            } else {
                $mismatches[] = $field;
            }
        }

        $total = count($declared);

        return ['ratio' => $total > 0 ? $verified / $total : 0.0, 'mismatches' => $mismatches];
    }

    /**
     * @param  array<string,int>  $columns
     * @param  list<array{row: int, role: string, name: string, resource_type: ?ResourceType}>  $itemRows
     */
    private function numericRatio(Worksheet $sheet, array $columns, array $itemRows, string $field): float
    {
        if ($itemRows === []) {
            return 0.0;
        }

        $matches = 0;

        foreach ($itemRows as $itemRow) {
            if (self::cleanNumeric(WorksheetCellReader::columnText($sheet, $columns, $field, $itemRow['row'])) !== null) {
                $matches++;
            }
        }

        return $matches / count($itemRows);
    }

    /**
     * @param  array<string,int>  $columns
     * @param  list<array{row: int, role: string, name: string, resource_type: ?ResourceType}>  $subtotalRows
     */
    private function costNumericRatio(Worksheet $sheet, array $columns, array $subtotalRows): ?float
    {
        if ($subtotalRows === []) {
            return null;
        }

        $matches = 0;

        foreach ($subtotalRows as $subtotalRow) {
            foreach (self::COST_COLUMN_FIELDS as $field) {
                if (self::cleanNumeric(WorksheetCellReader::columnText($sheet, $columns, $field, $subtotalRow['row'])) !== null) {
                    $matches++;

                    continue 2;
                }
            }
        }

        return $matches / count($subtotalRows);
    }

    /**
     * Справочная доля арифметической сходимости `quantity × unit_price_curr ≈ total_price_curr`
     * (bcmath ±0.01, только строки роли `item`, где есть все три величины) — НЕ входит в гейт
     * (базисно-индексный расчёт законно расходится), но публикуется в отчёт как сигнал неверно
     * сопоставленных колонок для ревью.
     *
     * @param  array<string,int>  $columns
     * @param  list<array{row: int, role: string, name: string, resource_type: ?ResourceType}>  $itemRows
     */
    private function arithmeticMatchRatio(Worksheet $sheet, array $columns, array $itemRows): ?float
    {
        $eligible = 0;
        $matches = 0;

        foreach ($itemRows as $itemRow) {
            $quantity = self::cleanNumeric(WorksheetCellReader::columnText($sheet, $columns, 'quantity', $itemRow['row']));
            $unitPrice = self::cleanNumeric(WorksheetCellReader::columnText($sheet, $columns, 'unit_price_curr', $itemRow['row']));
            $totalPrice = self::cleanNumeric(WorksheetCellReader::columnText($sheet, $columns, 'total_price_curr', $itemRow['row']));

            if ($quantity === null || $unitPrice === null || $totalPrice === null) {
                continue;
            }

            $eligible++;
            $expected = bcmul($quantity, $unitPrice, 4);
            $diff = bcsub($expected, $totalPrice, 4);

            if (bccomp($diff, '0.01', 4) <= 0 && bccomp($diff, '-0.01', 4) >= 0) {
                $matches++;
            }
        }

        return $eligible > 0 ? $matches / $eligible : null;
    }

    private function hasCostColumn(GoldFormProfileColumns $columns): bool
    {
        return array_any(
            self::COST_COLUMN_FIELDS,
            static fn (string $field): bool => $columns->{$field} !== null,
        );
    }

    /**
     * @param  array<string,int>  $columns
     * @return array<string,string> поле → текст ячейки (только НЕПУСТЫЕ значения); [] — строка
     *                              полностью пуста в пределах колонок профиля
     */
    private function rowCellTexts(Worksheet $sheet, array $columns, int $row): array
    {
        $result = [];

        foreach ($columns as $field => $columnIndex) {
            $text = WorksheetCellReader::cellText($sheet, $columnIndex, $row);

            if ($text !== '') {
                $result[$field] = $text;
            }
        }

        return $result;
    }

    /**
     * @param  array<string,string>  $cells  поле → текст (только непустые)
     * @param  array<string,int>  $sortedColumns  поле → индекс колонки, отсортировано по индексу
     */
    private function firstNonEmptyText(array $cells, array $sortedColumns): string
    {
        foreach (array_keys($sortedColumns) as $field) {
            if (isset($cells[$field])) {
                return $cells[$field];
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function matchesAnyPrefix(string $text, array $prefixes): bool
    {
        $trimmed = trim($text);

        return array_any(
            $prefixes,
            static fn (string $prefix): bool => str_starts_with($trimmed, $prefix),
        );
    }

    /**
     * @param  list<GoldResourceGroup>  $groups
     */
    private function matchResourceGroup(string $text, array $groups): ?GoldResourceGroup
    {
        $trimmed = trim($text);

        return array_find(
            $groups,
            static fn (GoldResourceGroup $group): bool => $trimmed === trim($group->label),
        );
    }

    private function normalizeHeaderText(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return mb_strtolower($collapsed, 'UTF-8');
    }
}
