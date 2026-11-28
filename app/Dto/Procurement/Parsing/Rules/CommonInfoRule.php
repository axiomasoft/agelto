<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use InvalidArgumentException;

/**
 * Правило сопоставления текстовых меток общей информации с полями закупки.
 */
final readonly class CommonInfoRule
{
    /**
     * @param  array<string, list<string>>  $fieldAliases
     * @param  list<string>  $dateFields
     * @param  list<string>  $moneyFields
     */
    public function __construct(
        public array $fieldAliases = [],
        public array $dateFields = [],
        public array $moneyFields = [],
        public ?DateParsingRule $dateParsing = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawAliases = $data['field_aliases'] ?? [];
        if (! is_array($rawAliases)) {
            throw new InvalidArgumentException('CommonInfoRule field_aliases must be an object.');
        }

        $fieldAliases = [];
        foreach ($rawAliases as $targetField => $aliases) {
            if (! is_string($targetField) || trim($targetField) === '' || ! is_array($aliases)) {
                throw new InvalidArgumentException('CommonInfoRule field_aliases must map field names to lists of aliases.');
            }
            $parsedAliases = [];
            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    throw new InvalidArgumentException('CommonInfoRule field alias must be a non-empty string.');
                }
                $parsedAliases[] = trim($alias);
            }
            $fieldAliases[trim($targetField)] = $parsedAliases;
        }

        $rawDateParsing = RuleData::nullableObject($data, 'date_parsing');

        return new self(
            fieldAliases: $fieldAliases,
            dateFields: RuleData::stringList($data, 'date_fields'),
            moneyFields: RuleData::stringList($data, 'money_fields'),
            dateParsing: $rawDateParsing !== null ? DateParsingRule::fromArray($rawDateParsing) : null,
        );
    }

    /**
     * @return array{
     *     field_aliases: array<string, list<string>>,
     *     date_fields: list<string>,
     *     money_fields: list<string>,
     *     date_parsing: array{formats: non-empty-list<string>, timezone: string, extract_regex: string|null}|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'field_aliases' => $this->fieldAliases,
            'date_fields' => $this->dateFields,
            'money_fields' => $this->moneyFields,
            'date_parsing' => $this->dateParsing?->toArray(),
        ];
    }
}
