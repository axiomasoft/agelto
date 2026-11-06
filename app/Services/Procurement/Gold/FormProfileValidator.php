<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Gold\GoldFormProfileColumns;
use App\Dto\Procurement\Gold\GoldHeaderQuote;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

final readonly class FormProfileValidator
{
    /**
     * @return array{0: int, 1: int}
     */
    public function buildHeaderRows(mixed $raw, string $context): array
    {
        if (! is_array($raw) || count($raw) !== 2) {
            throw new RuntimeException("{$context}.header_rows: обязан быть парой [первая, последняя]");
        }

        $pair = array_values($raw);
        $start = $this->intFrom($pair[0], "{$context}.header_rows.0");
        $end = $this->intFrom($pair[1], "{$context}.header_rows.1");

        if ($start > $end) {
            throw new RuntimeException("{$context}.header_rows: первая строка ({$start}) больше последней ({$end})");
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array{0: int, 1: int}  $headerRows
     * @return array<string, list<GoldHeaderQuote>>
     */
    public function buildHeaderCells(array $raw, GoldFormProfileColumns $columns, array $headerRows, string $context): array
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfileColumns::FIELDS, "{$context}.header_cells");

        /** @var array<string, list<GoldHeaderQuote>> $result */
        $result = [];

        foreach ($raw as $field => $quotesRaw) {
            if (! is_array($quotesRaw) || $quotesRaw === []) {
                throw new RuntimeException("{$context}.header_cells.{$field}: обязан быть непустым списком цитат");
            }

            $declaredLetter = $columns->letterFor($field);
            $quotes = [];
            $hasOwnColumnPart = false;

            foreach (array_values($quotesRaw) as $quoteIndex => $quoteRaw) {
                if (! is_array($quoteRaw)) {
                    throw new RuntimeException("{$context}.header_cells.{$field}.{$quoteIndex}: обязан быть объектом {cell, text}");
                }

                /** @var array<string, mixed> $quoteRaw */
                $this->assertNoUnknownKeys($quoteRaw, ['cell', 'text'], "{$context}.header_cells.{$field}.{$quoteIndex}");

                $quote = new GoldHeaderQuote(
                    cell: $this->stringValue($quoteRaw, 'cell', $context),
                    text: $this->stringValue($quoteRaw, 'text', $context),
                );

                [$quoteColumn, $quoteRow] = $this->parseCellAddress($quote->cell, "{$context}.header_cells.{$field}.{$quoteIndex}");

                if ($quoteRow < $headerRows[0] || $quoteRow > $headerRows[1]) {
                    throw new RuntimeException("{$context}.header_cells.{$field}.{$quoteIndex}: адрес {$quote->cell} лежит вне header_rows [{$headerRows[0]}, {$headerRows[1]}]");
                }

                if ($declaredLetter !== null && strcasecmp($quoteColumn, $declaredLetter) === 0) {
                    $hasOwnColumnPart = true;
                }

                $quotes[] = $quote;
            }

            if ($declaredLetter !== null && ! $hasOwnColumnPart) {
                throw new RuntimeException("{$context}.header_cells.{$field}: ни одна часть цитаты не лежит в самой колонке поля ({$declaredLetter})");
            }

            $result[$field] = $quotes;
        }

        foreach (GoldFormProfileColumns::FIELDS as $field) {
            if ($columns->{$field} !== null && ! isset($result[$field])) {
                throw new RuntimeException("{$context}.header_cells: отсутствует у объявленной колонки {$field}");
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $ordinals
     */
    public function assertOrdinalsSequential(array $ordinals, string $context): void
    {
        $sorted = $ordinals;
        sort($sorted);

        if ($sorted !== range(1, count($sorted))) {
            throw new RuntimeException("{$context}: ordinal обязан образовывать 1..N без дыр, получено [".implode(', ', $ordinals).']');
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowed
     */
    public function assertNoUnknownKeys(array $raw, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($raw), $allowed);

        if ($unknown !== []) {
            throw new RuntimeException("{$context}: неизвестный ключ: ".implode(', ', $unknown));
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<mixed, mixed>
     */
    public function arrayValue(array $raw, string $key, string $context): array
    {
        $value = $raw[$key] ?? [];

        if (! is_array($value)) {
            throw new RuntimeException("{$context}.{$key}: обязан быть объектом или списком");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function objectValue(array $raw, string $key, string $context): array
    {
        $value = $this->arrayValue($raw, $key, $context);

        foreach (array_keys($value) as $nestedKey) {
            if (! is_string($nestedKey)) {
                throw new RuntimeException("{$context}.{$key}: обязан быть JSON-объектом");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $raw */
    public function stringValue(array $raw, string $key, string $context): string
    {
        $value = $raw[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("{$context}.{$key}: обязан быть строкой");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    public function nullableStringValue(array $raw, string $key, string $context): ?string
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        return $this->stringValue($raw, $key, $context);
    }

    /** @param array<string, mixed> $raw */
    public function intValue(array $raw, string $key, string $context): int
    {
        return $this->intFrom($raw[$key] ?? null, "{$context}.{$key}");
    }

    public function intFrom(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException("{$context}: обязан быть целым числом");
    }

    /** @param array<string, mixed> $raw */
    public function nullableIntValue(array $raw, string $key, string $context): ?int
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        return $this->intValue($raw, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    public function stringListValue(array $raw, string $key, string $context): array
    {
        $values = $this->arrayValue($raw, $key, $context);
        $result = [];

        foreach (array_values($values) as $index => $value) {
            if (! is_string($value)) {
                throw new RuntimeException("{$context}.{$key}.{$index}: обязан быть строкой");
            }

            $result[] = $value;
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseCellAddress(string $cell, string $context): array
    {
        try {
            [$column, $row] = Coordinate::coordinateFromString($cell);
        } catch (\Throwable $e) {
            throw new RuntimeException("{$context}: адрес ячейки «{$cell}» невалиден", previous: $e);
        }

        return [$column, (int) $row];
    }
}
