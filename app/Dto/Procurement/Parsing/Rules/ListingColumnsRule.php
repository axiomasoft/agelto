<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\ColumnMappingRule;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use InvalidArgumentException;

/**
 * Правило разбора колонок табличного или HTML-листинга.
 */
final readonly class ListingColumnsRule
{
    /**
     * @param  non-empty-list<ColumnMappingRule>  $columns
     */
    public function __construct(
        public array $columns,
        public ?SelectorChain $rowSelectors = null,
        public ?string $deduplicateByField = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawColumns = $data['columns'] ?? null;
        if (! is_array($rawColumns) || $rawColumns === []) {
            throw new InvalidArgumentException('ListingColumnsRule requires non-empty columns list.');
        }

        $columns = [];
        foreach ($rawColumns as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('ListingColumnsRule column entry must be an array.');
            }
            $columns[] = ColumnMappingRule::fromArray($item);
        }

        return new self(
            columns: $columns,
            rowSelectors: RuleData::nullableSelectorChain($data, 'row_selectors'),
            deduplicateByField: RuleData::nullableString($data, 'deduplicate_by_field'),
        );
    }

    /**
     * @return array{
     *     columns: non-empty-list<array{
     *         target_field: string,
     *         header_aliases: list<string>,
     *         column_index: int|null,
     *         required: bool,
     *         value_regex: string|null,
     *     }>,
     *     row_selectors: non-empty-list<string>|null,
     *     deduplicate_by_field: string|null,
     * }
     */
    public function toArray(): array
    {
        $columns = [];
        foreach ($this->columns as $column) {
            $columns[] = $column->toArray();
        }

        return [
            'columns' => $columns,
            'row_selectors' => $this->rowSelectors?->toArray(),
            'deduplicate_by_field' => $this->deduplicateByField,
        ];
    }
}
