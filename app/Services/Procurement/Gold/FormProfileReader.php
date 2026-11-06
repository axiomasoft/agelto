<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Dto\Procurement\Gold\GoldExplicitRow;
use App\Dto\Procurement\Gold\GoldFormProfile;
use App\Dto\Procurement\Gold\GoldFormProfileColumns;
use App\Dto\Procurement\Gold\GoldFormProfileEstimate;
use App\Dto\Procurement\Gold\GoldResourceGroup;
use App\Dto\Procurement\Gold\GoldRowRules;
use App\Enums\Procurement\Estimate\ResourceType;
use App\Enums\Procurement\Estimate\RowType;
use RuntimeException;

/**
 * Чтение `estimates/gold/<CODE>.profile.json` (version 2) в типизированное дерево
 * {@see GoldFormProfile}.
 */
final readonly class FormProfileReader
{
    public function __construct(
        private FormProfileValidator $validator = new FormProfileValidator,
    ) {}

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
        $this->validator->assertNoUnknownKeys($raw, GoldFormProfile::FIELDS, $context);

        $version = $this->validator->intValue($raw, 'version', $context);

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

        $this->validator->assertOrdinalsSequential($ordinals, $context);

        return new GoldFormProfile(
            version: $version,
            code: $this->validator->stringValue($raw, 'code', $context),
            estimates: $estimates,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildEstimate(array $raw, string $context): GoldFormProfileEstimate
    {
        $this->validator->assertNoUnknownKeys($raw, GoldFormProfileEstimate::FIELDS, $context);

        $headerRows = $this->validator->buildHeaderRows($this->validator->arrayValue($raw, 'header_rows', $context), $context);
        $columns = $this->buildColumns($this->validator->objectValue($raw, 'columns', $context), $context);
        $headerCells = $this->validator->buildHeaderCells($this->validator->objectValue($raw, 'header_cells', $context), $columns, $headerRows, $context);
        $rowRules = $this->buildRowRules($this->validator->objectValue($raw, 'row_rules', $context), $context);

        $dataRowStart = $this->validator->intValue($raw, 'data_row_start', $context);

        if ($dataRowStart <= $headerRows[1]) {
            throw new RuntimeException("{$context}: data_row_start ({$dataRowStart}) обязан быть больше последней строки header_rows ({$headerRows[1]})");
        }

        return new GoldFormProfileEstimate(
            ordinal: $this->validator->intValue($raw, 'ordinal', $context),
            sheet: $this->validator->stringValue($raw, 'sheet', $context),
            header_rows: $headerRows,
            data_row_start: $dataRowStart,
            data_row_end: $this->validator->nullableIntValue($raw, 'data_row_end', $context),
            columns: $columns,
            header_cells: $headerCells,
            row_rules: $rowRules,
            note: $this->validator->nullableStringValue($raw, 'note', $context),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildColumns(array $raw, string $context): GoldFormProfileColumns
    {
        $this->validator->assertNoUnknownKeys($raw, GoldFormProfileColumns::FIELDS, "{$context}.columns");

        try {
            return GoldFormProfileColumns::validateAndCreate($raw);
        } catch (\Throwable $e) {
            throw new RuntimeException("{$context}.columns: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildRowRules(array $raw, string $context): GoldRowRules
    {
        $this->validator->assertNoUnknownKeys($raw, GoldRowRules::FIELDS, "{$context}.row_rules");

        $resourceGroups = [];
        foreach (array_values($this->validator->arrayValue($raw, 'resource_groups', $context)) as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw new RuntimeException("{$context}.row_rules.resource_groups.{$index}: обязан быть объектом");
            }

            /** @var array<string, mixed> $rawGroup */
            $resourceGroups[] = $this->buildResourceGroup($rawGroup, "{$context}.row_rules.resource_groups.{$index}");
        }

        $explicitRows = [];
        foreach (array_values($this->validator->arrayValue($raw, 'explicit_rows', $context)) as $index => $rawRow) {
            if (! is_array($rawRow)) {
                throw new RuntimeException("{$context}.row_rules.explicit_rows.{$index}: обязан быть объектом");
            }

            /** @var array<string, mixed> $rawRow */
            $explicitRows[] = $this->buildExplicitRow($rawRow, "{$context}.row_rules.explicit_rows.{$index}");
        }

        return new GoldRowRules(
            item_number_column: $this->validator->stringValue($raw, 'item_number_column', $context),
            section_prefixes: $this->validator->stringListValue($raw, 'section_prefixes', $context),
            subsection_prefixes: $this->validator->stringListValue($raw, 'subsection_prefixes', $context),
            subtotal_prefixes: $this->validator->stringListValue($raw, 'subtotal_prefixes', $context),
            summary_prefixes: $this->validator->stringListValue($raw, 'summary_prefixes', $context),
            ignored_prefixes: $this->validator->stringListValue($raw, 'ignored_prefixes', $context),
            resource_groups: $resourceGroups,
            explicit_rows: $explicitRows,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildResourceGroup(array $raw, string $context): GoldResourceGroup
    {
        $this->validator->assertNoUnknownKeys($raw, ['label', 'resource_type'], $context);

        $resourceTypeValue = $this->validator->stringValue($raw, 'resource_type', $context);
        $resourceType = ResourceType::tryFrom($resourceTypeValue);

        if ($resourceType === null) {
            throw new RuntimeException("{$context}.resource_type: «{$resourceTypeValue}» вне enum ResourceType");
        }

        return new GoldResourceGroup(
            label: $this->validator->stringValue($raw, 'label', $context),
            resource_type: $resourceType,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function buildExplicitRow(array $raw, string $context): GoldExplicitRow
    {
        $this->validator->assertNoUnknownKeys($raw, ['row', 'role', 'text'], $context);

        $role = $this->validator->stringValue($raw, 'role', $context);
        $allowedRoles = array_map(static fn (RowType $c): string => $c->value, RowType::cases());
        $allowedRoles[] = 'ignored';

        if (! in_array($role, $allowedRoles, true)) {
            throw new RuntimeException("{$context}.role: «{$role}» вне enum (RowType ∪ ignored)");
        }

        return new GoldExplicitRow(
            row: $this->validator->intValue($raw, 'row', $context),
            role: $role,
            text: $this->validator->stringValue($raw, 'text', $context),
        );
    }
}
