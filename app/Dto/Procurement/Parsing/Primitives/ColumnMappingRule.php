<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Primitives;

use InvalidArgumentException;

/**
 * Правило сопоставления колонки таблицы с целевым полем доменной модели.
 */
final readonly class ColumnMappingRule
{
    /**
     * @param  list<string>  $headerAliases
     */
    public function __construct(
        public string $targetField,
        public array $headerAliases,
        public ?int $columnIndex = null,
        public bool $required = false,
        public ?RegexPattern $valueRegex = null,
    ) {
        if (trim($targetField) === '') {
            throw new InvalidArgumentException('ColumnMappingRule targetField must not be empty.');
        }

        if ($columnIndex !== null && $columnIndex < 0) {
            throw new InvalidArgumentException('ColumnMappingRule columnIndex must be >= 0.');
        }

        if ($headerAliases === [] && $columnIndex === null) {
            throw new InvalidArgumentException('ColumnMappingRule must define headerAliases or columnIndex.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $targetField = $data['target_field'] ?? null;
        if (! is_string($targetField) || trim($targetField) === '') {
            throw new InvalidArgumentException('ColumnMappingRule requires non-empty string target_field.');
        }

        $rawAliases = $data['header_aliases'] ?? [];
        if (! is_array($rawAliases)) {
            throw new InvalidArgumentException('ColumnMappingRule header_aliases must be an array.');
        }

        $aliases = [];
        foreach ($rawAliases as $alias) {
            if (! is_string($alias) || trim($alias) === '') {
                throw new InvalidArgumentException('ColumnMappingRule header_aliases entries must be non-empty strings.');
            }
            $aliases[] = trim($alias);
        }

        $rawColumnIndex = $data['column_index'] ?? null;
        if ($rawColumnIndex !== null && ! is_int($rawColumnIndex)) {
            throw new InvalidArgumentException('ColumnMappingRule column_index must be an integer or null.');
        }

        $rawRequired = $data['required'] ?? false;
        if (! is_bool($rawRequired)) {
            throw new InvalidArgumentException('ColumnMappingRule required must be a boolean.');
        }

        $rawRegex = $data['value_regex'] ?? null;
        $valueRegex = null;
        if ($rawRegex !== null) {
            if (! is_string($rawRegex)) {
                throw new InvalidArgumentException('ColumnMappingRule value_regex must be a string or null.');
            }
            $valueRegex = new RegexPattern($rawRegex);
        }

        return new self(
            targetField: trim($targetField),
            headerAliases: $aliases,
            columnIndex: $rawColumnIndex,
            required: $rawRequired,
            valueRegex: $valueRegex,
        );
    }

    /**
     * @return array{
     *     target_field: string,
     *     header_aliases: list<string>,
     *     column_index: int|null,
     *     required: bool,
     *     value_regex: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'target_field' => $this->targetField,
            'header_aliases' => $this->headerAliases,
            'column_index' => $this->columnIndex,
            'required' => $this->required,
            'value_regex' => $this->valueRegex?->pattern,
        ];
    }
}
