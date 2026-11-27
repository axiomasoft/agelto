<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Gold\GoldFormProfileColumns;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class WorksheetCellReader
{
    /**
     * @return array<string, int>
     */
    public static function declaredColumnIndexes(GoldFormProfileColumns $columns): array
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
     * @param  array<string, int>  $columns
     */
    public static function columnText(Worksheet $sheet, array $columns, string $field, int $row): ?string
    {
        if (! isset($columns[$field])) {
            return null;
        }

        $text = self::cellText($sheet, $columns[$field], $row);

        return $text === '' ? null : $text;
    }

    public static function cellText(Worksheet $sheet, int $column, int $row): string
    {
        $value = $sheet->getCell([$column, $row])->getCalculatedValue();

        return self::stringify($value);
    }

    public static function stringify(mixed $value): string
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

        return is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
    }
}
