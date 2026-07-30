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
use App\Enums\Procurement\ResourceType;
use App\Enums\Procurement\RowType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RuntimeException;

/**
 * Чтение `estimates/gold/<CODE>.profile.json` (version 2, P5.2, D117) в типизированное дерево
 * {@see GoldFormProfile}. Отклоняет разметку, которая недоказана СТРУКТУРНО (без обращения к
 * листу книги — это работа {@see GridRecognitionGate} условие (а)): неизвестный ключ на любом
 * уровне; версия ≠ 2; `header_cells` отсутствует у объявленной колонки; адрес цитаты лежит вне
 * `header_rows`; ни одна часть цитаты не лежит в САМОЙ колонке поля; `data_row_start` ≤
 * последней строке `header_rows`; `ordinal` не образует 1..N без дыр; `resource_type` вне enum
 * `ResourceType`; `role` вне enum. Отказ — исключение с адресом дефекта (какой эталон, какая
 * смета/поле), а не молчаливый пропуск (Implementation Rules P5.2).
 *
 * Лишний ключ отклоняется ЭТИМ чтением на каждом уровне вложенности (канон `EstimateRow::
 * validateAndCreate()`, P4.5): явная проверка `array_diff` ПЕРЕД делегированием типовой валидации
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

        return $this->fromDecoded($decoded, $path);
    }

    public function fromDecoded(array $raw, string $context): GoldFormProfile
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfile::FIELDS, $context);

        $version = (int) ($raw['version'] ?? 0);

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

            $estimate = $this->buildEstimate($rawEstimate, "{$context}: estimates.{$index}");
            $estimates[] = $estimate;
            $ordinals[] = $estimate->ordinal;
        }

        $this->assertOrdinalsSequential($ordinals, $context);

        return new GoldFormProfile(
            version: $version,
            code: (string) ($raw['code'] ?? ''),
            estimates: $estimates,
        );
    }

    private function buildEstimate(array $raw, string $context): GoldFormProfileEstimate
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfileEstimate::FIELDS, $context);

        $headerRows = $this->buildHeaderRows($raw['header_rows'] ?? null, $context);
        $columns = $this->buildColumns($raw['columns'] ?? [], $context);
        $headerCells = $this->buildHeaderCells($raw['header_cells'] ?? [], $columns, $headerRows, $context);
        $rowRules = $this->buildRowRules($raw['row_rules'] ?? [], $context);

        $dataRowStart = (int) ($raw['data_row_start'] ?? 0);

        if ($dataRowStart <= $headerRows[1]) {
            throw new RuntimeException("{$context}: data_row_start ({$dataRowStart}) обязан быть больше последней строки header_rows ({$headerRows[1]})");
        }

        return new GoldFormProfileEstimate(
            ordinal: (int) ($raw['ordinal'] ?? 0),
            sheet: (string) ($raw['sheet'] ?? ''),
            header_rows: $headerRows,
            data_row_start: $dataRowStart,
            data_row_end: isset($raw['data_row_end']) ? (int) $raw['data_row_end'] : null,
            columns: $columns,
            header_cells: $headerCells,
            row_rules: $rowRules,
            note: isset($raw['note']) ? (string) $raw['note'] : null,
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
        $start = (int) $pair[0];
        $end = (int) $pair[1];

        if ($start > $end) {
            throw new RuntimeException("{$context}.header_rows: первая строка ({$start}) больше последней ({$end})");
        }

        return [$start, $end];
    }

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
     * @param  array{0: int, 1: int}  $headerRows
     * @return array<string, list<GoldHeaderQuote>>
     */
    private function buildHeaderCells(array $raw, GoldFormProfileColumns $columns, array $headerRows, string $context): array
    {
        $this->assertNoUnknownKeys($raw, GoldFormProfileColumns::FIELDS, "{$context}.header_cells");

        $result = [];

        foreach ($raw as $field => $quotesRaw) {
            if (! is_array($quotesRaw) || $quotesRaw === []) {
                throw new RuntimeException("{$context}.header_cells.{$field}: обязан быть непустым списком цитат");
            }

            $declaredLetter = $columns->{$field};
            $quotes = [];
            $hasOwnColumnPart = false;

            foreach (array_values($quotesRaw) as $quoteIndex => $quoteRaw) {
                if (! is_array($quoteRaw)) {
                    throw new RuntimeException("{$context}.header_cells.{$field}.{$quoteIndex}: обязан быть объектом {cell, text}");
                }

                $this->assertNoUnknownKeys($quoteRaw, ['cell', 'text'], "{$context}.header_cells.{$field}.{$quoteIndex}");

                $quote = new GoldHeaderQuote(
                    cell: (string) ($quoteRaw['cell'] ?? ''),
                    text: (string) ($quoteRaw['text'] ?? ''),
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

    private function buildRowRules(array $raw, string $context): GoldRowRules
    {
        $this->assertNoUnknownKeys($raw, GoldRowRules::FIELDS, "{$context}.row_rules");

        $resourceGroups = [];
        foreach (array_values($raw['resource_groups'] ?? []) as $index => $rawGroup) {
            $resourceGroups[] = $this->buildResourceGroup((array) $rawGroup, "{$context}.row_rules.resource_groups.{$index}");
        }

        $explicitRows = [];
        foreach (array_values($raw['explicit_rows'] ?? []) as $index => $rawRow) {
            $explicitRows[] = $this->buildExplicitRow((array) $rawRow, "{$context}.row_rules.explicit_rows.{$index}");
        }

        return new GoldRowRules(
            item_number_column: (string) ($raw['item_number_column'] ?? ''),
            section_prefixes: array_map('strval', $raw['section_prefixes'] ?? []),
            subsection_prefixes: array_map('strval', $raw['subsection_prefixes'] ?? []),
            subtotal_prefixes: array_map('strval', $raw['subtotal_prefixes'] ?? []),
            summary_prefixes: array_map('strval', $raw['summary_prefixes'] ?? []),
            ignored_prefixes: array_map('strval', $raw['ignored_prefixes'] ?? []),
            resource_groups: $resourceGroups,
            explicit_rows: $explicitRows,
        );
    }

    private function buildResourceGroup(array $raw, string $context): GoldResourceGroup
    {
        $this->assertNoUnknownKeys($raw, ['label', 'resource_type'], $context);

        $resourceType = ResourceType::tryFrom((string) ($raw['resource_type'] ?? ''));

        if ($resourceType === null) {
            throw new RuntimeException("{$context}.resource_type: «{$raw['resource_type']}» вне enum ResourceType");
        }

        return new GoldResourceGroup(
            label: (string) ($raw['label'] ?? ''),
            resource_type: $resourceType,
        );
    }

    private function buildExplicitRow(array $raw, string $context): GoldExplicitRow
    {
        $this->assertNoUnknownKeys($raw, ['row', 'role', 'text'], $context);

        $role = (string) ($raw['role'] ?? '');
        $allowedRoles = array_map(static fn (RowType $c): string => $c->value, RowType::cases());
        $allowedRoles[] = 'ignored';

        if (! in_array($role, $allowedRoles, true)) {
            throw new RuntimeException("{$context}.role: «{$role}» вне enum (RowType ∪ ignored)");
        }

        return new GoldExplicitRow(
            row: (int) ($raw['row'] ?? 0),
            role: $role,
            text: (string) ($raw['text'] ?? ''),
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
    private function assertNoUnknownKeys(array $raw, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($raw), $allowed);

        if ($unknown !== []) {
            throw new RuntimeException("{$context}: неизвестный ключ: ".implode(', ', $unknown));
        }
    }
}
