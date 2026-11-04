<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Analyzers\Estimate\EstimatePassport;
use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowAttributes;
use App\Dto\Procurement\Analyzers\Estimate\RowResource;
use App\Dto\Procurement\Gold\GoldFormProfile;
use App\Dto\Procurement\Gold\GoldFormProfileColumns;
use App\Dto\Procurement\Gold\GoldFormProfileEstimate;
use App\Enums\Procurement\Estimate\ResourceType;
use App\Enums\Procurement\Estimate\RowType;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Книга и профиль формы преобразуются в gold дословно. Row_index идёт с 1 внутри
 * каждой сметы, включает заголовки и итоги; ресурсы и ignored не занимают номера.
 * Source_position_number берётся из item_number_column. Ресурсы относятся к последнему item.
 * Стоимость subtotal остаётся у своей строки, пустые значения не выводятся из агрегатов.
 * КС-3 заполняется только у summary_row с объявленными колонками. Профиль описывает таблицу,
 * поэтому passport пуст, currency=RUB, totals.footer и work_types пусты.
 * Роли определяет общий с gate GridRecognitionGate::classifyRows().
 */
/**
 * @phpstan-type MappedRow array{row_index: int, row_type: RowType, name: string, section_title: string|null, code: string|null, unit: string|null, quantity: string|null, unit_price_base: string|null, unit_price_curr: string|null, total_price_base: string|null, total_price_curr: string|null, index_value: string|null, source_position_number: string|null, resources: list<RowResource>, attributes: RowAttributes|null}
 */
final readonly class SheetGoldMapper
{
    public function __construct(
        private GridRecognitionGate $gate,
        private GoldValidator $validator,
    ) {}

    /**
     * @throws ValidationException Вывод не прошёл {@see GoldValidator} — маппер считается упавшим.
     */
    /**
     * @return array{estimates: list<array<string, mixed>>}
     */
    public function toGold(Spreadsheet $spreadsheet, GoldFormProfile $profile): array
    {
        $estimates = [];

        foreach ($profile->estimates as $estimate) {
            $sheet = $spreadsheet->getSheetByName($estimate->sheet);

            if ($sheet === null) {
                throw new RuntimeException("Лист «{$estimate->sheet}» не найден в книге (профиль {$profile->code}, estimate {$estimate->ordinal})");
            }

            $estimates[] = $this->mapEstimate($sheet, $estimate);
        }

        $gold = ['estimates' => $estimates];

        $this->validator->assertValid($gold);

        return $gold;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEstimate(Worksheet $sheet, GoldFormProfileEstimate $estimate): array
    {
        $classified = $this->gate->classifyRows($sheet, $estimate);
        $columns = $this->declaredColumnIndexes($estimate->columns);

        /** @var list<MappedRow> $rows */
        $rows = [];
        $rowIndex = 0;
        $sectionTitle = null;
        $lastItemIndex = null;

        foreach ($classified as $entry) {
            $role = $entry['role'];

            if ($role === 'ignored' || $role === 'resource_marker') {
                continue;
            }

            if ($role === 'resource') {
                if ($lastItemIndex === null) {
                    throw new RuntimeException("Ресурс в строке {$entry['row']} не привязан к item-строке.");
                }

                $rows[$lastItemIndex] = $this->withResource(
                    $rows[$lastItemIndex],
                    $this->mapResource($sheet, $columns, $entry),
                );

                continue;
            }

            if ($role === 'uncovered') {
                continue;
            }

            $rowIndex++;
            $rowType = RowType::from($role);

            $rows[] = $this->mapRow($sheet, $columns, $entry, $rowType, $rowIndex, $sectionTitle);
            $lastRowIndex = count($rows) - 1;

            if ($rowType === RowType::SectionHeader || $rowType === RowType::SubsectionHeader) {
                $sectionTitle = $rows[$lastRowIndex]['name'];
            }

            if ($rowType === RowType::Item) {
                $lastItemIndex = $lastRowIndex;
            }
        }

        $estimateRows = [];

        foreach ($rows as $row) {
            $estimateRows[] = (new EstimateRow(
                row_index: $row['row_index'],
                row_type: $row['row_type'],
                name: $row['name'],
                section_title: $row['section_title'],
                code: $row['code'],
                unit: $row['unit'],
                quantity: $row['quantity'],
                unit_price_base: $row['unit_price_base'],
                unit_price_curr: $row['unit_price_curr'],
                total_price_base: $row['total_price_base'],
                total_price_curr: $row['total_price_curr'],
                index_value: $row['index_value'],
                source_position_number: $row['source_position_number'],
                resources: $row['resources'],
                attributes: $row['attributes'],
            ))->toArray();
        }

        $passport = new PassportResponse(passport: new EstimatePassport, rows_expected: count($estimateRows));

        /** @var array<string, mixed> $payload */
        $payload = $passport->toArray();
        $payload['rows'] = $estimateRows;

        return $payload;
    }

    /**
     * @param  array<string, int>  $columns
     * @param  array{row: int, role: string, name: string, resource_type: ?ResourceType}  $entry
     * @return MappedRow
     */
    private function mapRow(Worksheet $sheet, array $columns, array $entry, RowType $rowType, int $rowIndex, ?string $sectionTitle): array
    {
        $common = $this->mapCommonFields($sheet, $columns, $entry['row']);

        return [
            'row_index' => $rowIndex,
            'row_type' => $rowType,
            'name' => $entry['name'],
            'section_title' => $sectionTitle,
            'code' => $common['code'],
            'unit' => $common['unit'],
            'quantity' => $common['quantity'],
            'unit_price_base' => $common['unit_price_base'],
            'unit_price_curr' => $common['unit_price_curr'],
            'total_price_base' => $common['total_price_base'],
            'total_price_curr' => $common['total_price_curr'],
            'index_value' => $common['index_value'],
            'source_position_number' => $common['source_position_number'],
            'resources' => [],
            'attributes' => $rowType === RowType::SummaryRow ? $this->mapSummaryAttributes($sheet, $columns, $entry['row']) : null,
        ];
    }

    /**
     * @param  MappedRow  $row
     * @return MappedRow
     */
    private function withResource(array $row, RowResource $resource): array
    {
        $row['resources'][] = $resource;

        return $row;
    }

    /**
     * @param  array<string,int>  $columns
     * @return array{code: ?string, unit: ?string, quantity: ?string, unit_price_base: ?string,
     *     unit_price_curr: ?string, total_price_base: ?string, total_price_curr: ?string,
     *     index_value: ?string, source_position_number: ?string}
     */
    private function mapCommonFields(Worksheet $sheet, array $columns, int $row): array
    {
        return [
            'code' => $this->columnText($sheet, $columns, 'code', $row),
            'unit' => $this->columnText($sheet, $columns, 'unit', $row),
            'quantity' => GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'quantity', $row)),
            'unit_price_base' => GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'unit_price_base', $row)),
            'unit_price_curr' => GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'unit_price_curr', $row)),
            'total_price_base' => GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'total_price_base', $row)),
            'total_price_curr' => GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'total_price_curr', $row)),
            'index_value' => GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'index_value', $row)),
            'source_position_number' => $this->columnText($sheet, $columns, 'source_position_number', $row),
        ];
    }

    /**
     * КС-3 — разрезы `amount_since_start`/`amount_since_year_start`/`amount_reporting_period`
     * ТОЛЬКО у `summary_row` и только если хотя бы один из трёх столбцов объявлен профилем
     * — иначе `null` (строка не несёт ни коэффициентов, ни разрезов).
     *
     * @param  array<string,int>  $columns
     */
    private function mapSummaryAttributes(Worksheet $sheet, array $columns, int $row): ?RowAttributes
    {
        $sinceStart = GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'amount_since_start', $row));
        $sinceYearStart = GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'amount_since_year_start', $row));
        $reportingPeriod = GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'amount_reporting_period', $row));

        if ($sinceStart === null && $sinceYearStart === null && $reportingPeriod === null) {
            return null;
        }

        return new RowAttributes(
            amount_since_start: $sinceStart,
            amount_since_year_start: $sinceYearStart,
            amount_reporting_period: $reportingPeriod,
        );
    }

    /**
     * @param  array<string,int>  $columns
     * @param  array{row: int, role: string, name: string, resource_type: ?ResourceType}  $entry
     */
    private function mapResource(Worksheet $sheet, array $columns, array $entry): RowResource
    {
        $resourceType = $entry['resource_type'];

        if ($resourceType === null) {
            throw new RuntimeException("Ресурс в строке {$entry['row']} не содержит resource_type.");
        }

        return new RowResource(
            resource_type: $resourceType,
            name: $entry['name'],
            code: $this->columnText($sheet, $columns, 'code', $entry['row']),
            unit: $this->columnText($sheet, $columns, 'unit', $entry['row']),
            quantity: GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'quantity', $entry['row'])),
            price_base: GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'unit_price_base', $entry['row'])),
            price_curr: GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'unit_price_curr', $entry['row'])),
            index_code: null,
            index_value: GridRecognitionGate::cleanNumeric($this->columnText($sheet, $columns, 'index_value', $entry['row'])),
        );
    }

    /**
     * @return array<string,int>
     */
    private function declaredColumnIndexes(GoldFormProfileColumns $columns): array
    {
        $result = [];

        foreach (GoldFormProfileColumns::FIELDS as $field) {
            $letter = $columns->letterFor($field);

            if ($letter !== null) {
                $result[$field] = Coordinate::columnIndexFromString($letter);
            }
        }

        return $result;
    }

    /**
     * @param  array<string,int>  $columns
     */
    private function columnText(Worksheet $sheet, array $columns, string $field, int $row): ?string
    {
        if (! isset($columns[$field])) {
            return null;
        }

        $value = $sheet->getCell([$columns[$field], $row])->getCalculatedValue();
        $text = $this->stringify($value);

        return $text === '' ? null : $text;
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

        if (is_int($value) || is_string($value)) {
            return trim((string) $value);
        }

        throw new RuntimeException('Значение ячейки должно быть скалярным.');
    }
}
