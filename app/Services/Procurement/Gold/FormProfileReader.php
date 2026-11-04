<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Gold\GoldExplicitRow;
use App\Dto\Procurement\Gold\GoldFormProfile;
use App\Dto\Procurement\Gold\GoldFormProfileColumns;
use App\Dto\Procurement\Gold\GoldFormProfileEstimate;
use App\Dto\Procurement\Gold\GoldHeaderQuote;
use App\Dto\Procurement\Gold\GoldResourceGroup;
use App\Dto\Procurement\Gold\GoldRowRules;
use App\Enums\Procurement\Estimate\ResourceType;
use App\Enums\Procurement\Estimate\RowType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/**
 * Чтение `estimates/gold/<CODE>.profile.json` (version 2) в типизированное дерево
 * {@see GoldFormProfile}. Отклоняет разметку, которая недоказана СТРУКТУРНО (без обращения к
 * листу книги — это работа {@see GridRecognitionGate} условие (а)): неизвестный ключ на любом
 * уровне; версия ≠ 2; `header_cells` отсутствует у объявленной колонки; адрес цитаты лежит вне
 * `header_rows`; ни одна часть цитаты не лежит в САМОЙ колонке поля; `data_row_start` ≤
 * последней строке `header_rows`; `ordinal` не образует 1..N без дыр; `resource_type` вне enum
 * `ResourceType`; `role` вне enum. Отказ — исключение с адресом дефекта (какой эталон, какая
 * смета/поле), а не молчаливый пропуск.
 *
 * Лишний ключ отклоняется ЭТИМ чтением на каждом уровне вложенности (канон `EstimateRow::
 * validateAndCreate()`): явная проверка `array_diff` ПЕРЕД делегированием типовой валидации
 * `Data::validateAndCreate()` листовым DTO (`GoldFormProfileColumns`) — сама спека
 * `spatie/laravel-data` лишние ключи молча игнорирует.
 */
final readonly class FormProfileReader
{
    public function read(string $path): GoldFormProfile
    {
        if (! is_file($path)) {
            throw new RuntimeException("Профиль не найден: {$path}");
        }

        try {
            $decoded = json_decode(file_get_contents($path) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Профиль {$path} не парсится как JSON: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Профиль {$path} обязан быть JSON-объектом");
        }

        /** @var array<string, mixed> $decoded */

        return $this->fromDecoded($decoded, $path);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public function fromDecoded(array $raw, string $context): GoldFormProfile
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfile::FIELDS, $context);

        $version = $this->intValue($raw, 'version', $context);

        if ($version !== GoldFormProfile::VERSION) {
            throw new RuntimeException("{$context}: версия профиля обязана быть ".GoldFormProfile::VERSION.", получено {$version}");
        }

        if (! is_array($raw['estimates'] ?? null) || $raw['estimates'] === []) {
            throw new RuntimeException("{$context}: estimates обязан быть непустым списком");
        }

        $estimates = [];
        $ordinals = [];

        foreach (array_values($raw['estimates']) as $index => $rawEstimate) {
            if (! is_array($rawEstimate)) {
                throw new RuntimeException("{$context}: estimates.{$index} обязан быть объектом");
            }

            /** @var array<string, mixed> $rawEstimate */
            $estimate = $this->buildEstimate($rawEstimate, "{$context}: estimates.{$index}");
            $estimates[] = $estimate;
            $ordinals[] = $estimate->ordinal;
        }

        $this->assertOrdinalsSequential($ordinals, $context);

        return new GoldFormProfile(
            version: $version,
            code: $this->stringValue($raw, 'code', $context),
            estimates: $estimates,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildEstimate(array $raw, string $context): GoldFormProfileEstimate
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfileEstimate::FIELDS, $context);

        $headerRows = $this->buildHeaderRows($this->arrayValue($raw, 'header_rows', $context), $context);
        $columns = $this->buildColumns($this->objectValue($raw, 'columns', $context), $context);
        $headerCells = $this->buildHeaderCells($this->objectValue($raw, 'header_cells', $context), $columns, $headerRows, $context);
        $rowRules = $this->buildRowRules($this->objectValue($raw, 'row_rules', $context), $context);

        $dataRowStart = $this->intValue($raw, 'data_row_start', $context);

        if ($dataRowStart <= $headerRows[1]) {
            throw new RuntimeException("{$context}: data_row_start ({$dataRowStart}) обязан быть больше последней строки header_rows ({$headerRows[1]})");
        }

        return new GoldFormProfileEstimate(
            ordinal: $this->intValue($raw, 'ordinal', $context),
            sheet: $this->stringValue($raw, 'sheet', $context),
            header_rows: $headerRows,
            data_row_start: $dataRowStart,
            data_row_end: $this->nullableIntValue($raw, 'data_row_end', $context),
            columns: $columns,
            header_cells: $headerCells,
            row_rules: $rowRules,
            note: $this->nullableStringValue($raw, 'note', $context),
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function buildHeaderRows(mixed $raw, string $context): array
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
     */
    private function buildColumns(array $raw, string $context): GoldFormProfileColumns
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfileColumns::FIELDS, "{$context}.columns");

        try {
            return GoldFormProfileColumns::validateAndCreate($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException("{$context}.columns: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array{0: int, 1: int}  $headerRows
     * @return array<string, list<GoldHeaderQuote>>
     */
    private function buildHeaderCells(array $raw, GoldFormProfileColumns $columns, array $headerRows, string $context): array
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

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildRowRules(array $raw, string $context): GoldRowRules
    {
        $this->assertNoUnknownKeys($raw, GoldRowRules::FIELDS, "{$context}.row_rules");

        $resourceGroups = [];
        foreach (array_values($this->arrayValue($raw, 'resource_groups', $context)) as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw new RuntimeException("{$context}.row_rules.resource_groups.{$index}: обязан быть объектом");
            }

            /** @var array<string, mixed> $rawGroup */
            $resourceGroups[] = $this->buildResourceGroup($rawGroup, "{$context}.row_rules.resource_groups.{$index}");
        }

        $explicitRows = [];
        foreach (array_values($this->arrayValue($raw, 'explicit_rows', $context)) as $index => $rawRow) {
            if (! is_array($rawRow)) {
                throw new RuntimeException("{$context}.row_rules.explicit_rows.{$index}: обязан быть объектом");
            }

            /** @var array<string, mixed> $rawRow */
            $explicitRows[] = $this->buildExplicitRow($rawRow, "{$context}.row_rules.explicit_rows.{$index}");
        }

        return new GoldRowRules(
            item_number_column: $this->stringValue($raw, 'item_number_column', $context),
            section_prefixes: $this->stringListValue($raw, 'section_prefixes', $context),
            subsection_prefixes: $this->stringListValue($raw, 'subsection_prefixes', $context),
            subtotal_prefixes: $this->stringListValue($raw, 'subtotal_prefixes', $context),
            summary_prefixes: $this->stringListValue($raw, 'summary_prefixes', $context),
            ignored_prefixes: $this->stringListValue($raw, 'ignored_prefixes', $context),
            resource_groups: $resourceGroups,
            explicit_rows: $explicitRows,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildResourceGroup(array $raw, string $context): GoldResourceGroup
    {
        $this->assertNoUnknownKeys($raw, ['label', 'resource_type'], $context);

        $resourceTypeValue = $this->stringValue($raw, 'resource_type', $context);
        $resourceType = ResourceType::tryFrom($resourceTypeValue);

        if ($resourceType === null) {
            throw new RuntimeException("{$context}.resource_type: «{$resourceTypeValue}» вне enum ResourceType");
        }

        return new GoldResourceGroup(
            label: $this->stringValue($raw, 'label', $context),
            resource_type: $resourceType,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildExplicitRow(array $raw, string $context): GoldExplicitRow
    {
        $this->assertNoUnknownKeys($raw, ['row', 'role', 'text'], $context);

        $role = $this->stringValue($raw, 'role', $context);
        $allowedRoles = array_map(static fn (RowType $c): string => $c->value, RowType::cases());
        $allowedRoles[] = 'ignored';

        if (! in_array($role, $allowedRoles, true)) {
            throw new RuntimeException("{$context}.role: «{$role}» вне enum (RowType ∪ ignored)");
        }

        return new GoldExplicitRow(
            row: $this->intValue($raw, 'row', $context),
            role: $role,
            text: $this->stringValue($raw, 'text', $context),
        );
    }

    /**
     * @param  list<int>  $ordinals
     */
    private function assertOrdinalsSequential(array $ordinals, string $context): void
    {
        $sorted = $ordinals;
        sort($sorted);

        if ($sorted !== range(1, count($sorted))) {
            throw new RuntimeException("{$context}: ordinal обязан образовывать 1..N без дыр, получено [".implode(', ', $ordinals).']');
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowed
     */
    private function assertNoUnknownKeys(array $raw, array $allowed, string $context): void
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
    private function arrayValue(array $raw, string $key, string $context): array
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
    private function objectValue(array $raw, string $key, string $context): array
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
    private function stringValue(array $raw, string $key, string $context): string
    {
        $value = $raw[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("{$context}.{$key}: обязан быть строкой");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private function nullableStringValue(array $raw, string $key, string $context): ?string
    {
        if (! array_key_exists($key, $raw) || $raw[$key] === null) {
            return null;
        }

        return $this->stringValue($raw, $key, $context);
    }

    /** @param array<string, mixed> $raw */
    private function intValue(array $raw, string $key, string $context): int
    {
        return $this->intFrom($raw[$key] ?? null, "{$context}.{$key}");
    }

    private function intFrom(mixed $value, string $context): int
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
    private function nullableIntValue(array $raw, string $key, string $context): ?int
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
    private function stringListValue(array $raw, string $key, string $context): array
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
}
