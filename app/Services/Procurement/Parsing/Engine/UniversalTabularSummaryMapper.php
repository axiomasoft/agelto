<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Extraction\MappedTabularSummary;
use App\Dto\Procurement\Parsing\Primitives\ColumnMappingRule;
use App\Dto\Procurement\Parsing\Rules\ListingColumnsRule;
use App\Services\Procurement\Parsing\Validation\SafeRegex;
use App\Services\Procurement\Platform\Contracts\TabularSummaryMapper;

/**
 * Универсальный маппер табличных строк в структурированные записи листинга.
 */
final class UniversalTabularSummaryMapper implements TabularSummaryMapper
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return array{items: list<array<string, string|null>>, summary: MappedTabularSummary}
     */
    public function map(array $headers, array $rows, ListingColumnsRule $rule): array
    {
        $normalizedHeaders = array_map(
            static fn (string $header): string => mb_strtolower(trim($header)),
            $headers,
        );

        /** @var array<string, int> $fieldIndexMap */
        $fieldIndexMap = [];
        /** @var array<int, true> $matchedHeaderIndexes */
        $matchedHeaderIndexes = [];
        $missingRequiredColumns = [];

        foreach ($rule->columns as $column) {
            $index = $this->resolveColumnIndex($column, $normalizedHeaders);
            if ($index === null) {
                if ($column->required) {
                    $missingRequiredColumns[] = $column->targetField;
                }

                continue;
            }

            $fieldIndexMap[$column->targetField] = $index;
            $matchedHeaderIndexes[$index] = true;
        }

        $unmatchedHeaders = [];
        foreach ($headers as $idx => $header) {
            if (! isset($matchedHeaderIndexes[$idx])) {
                $unmatchedHeaders[] = $header;
            }
        }

        $items = [];
        $seenKeys = [];
        $skippedRows = 0;

        foreach ($rows as $row) {
            $record = [];
            $missingRequiredValue = false;

            foreach ($rule->columns as $column) {
                if (! array_key_exists($column->targetField, $fieldIndexMap)) {
                    if ($column->required) {
                        $missingRequiredValue = true;
                    }

                    continue;
                }

                $index = $fieldIndexMap[$column->targetField];
                $rawValue = $row[$index] ?? null;
                $value = $rawValue !== null && trim($rawValue) !== '' ? trim($rawValue) : null;

                if ($value !== null && $column->valueRegex !== null) {
                    $matches = [];
                    if (SafeRegex::match($column->valueRegex, $value, $matches)) {
                        $captured = $matches[1] ?? $matches[0] ?? null;
                        $value = is_string($captured) && trim($captured) !== '' ? trim($captured) : null;
                    } else {
                        $value = null;
                    }
                }

                if ($column->required && $value === null) {
                    $missingRequiredValue = true;
                }

                $record[$column->targetField] = $value;
            }

            if ($missingRequiredValue) {
                $skippedRows++;

                continue;
            }

            $dedupField = $rule->deduplicateByField;
            if ($dedupField !== null && isset($record[$dedupField])) {
                $dedupKey = $record[$dedupField];
                if (isset($seenKeys[$dedupKey])) {
                    $skippedRows++;

                    continue;
                }
                $seenKeys[$dedupKey] = true;
            }

            $items[] = $record;
        }

        return [
            'items' => $items,
            'summary' => new MappedTabularSummary(
                totalRows: count($rows),
                mappedRows: count($items),
                skippedRows: $skippedRows,
                unmatchedHeaders: $unmatchedHeaders,
                missingRequiredColumns: $missingRequiredColumns,
            ),
        ];
    }

    /**
     * @param  list<string>  $normalizedHeaders
     */
    private function resolveColumnIndex(ColumnMappingRule $column, array $normalizedHeaders): ?int
    {
        if ($column->columnIndex !== null && array_key_exists($column->columnIndex, $normalizedHeaders)) {
            return $column->columnIndex;
        }

        foreach ($column->headerAliases as $alias) {
            $needle = mb_strtolower(trim($alias));
            $found = array_search($needle, $normalizedHeaders, true);
            if (is_int($found)) {
                return $found;
            }
        }

        return null;
    }
}
