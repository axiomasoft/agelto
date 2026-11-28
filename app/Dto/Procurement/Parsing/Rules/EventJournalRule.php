<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\EventClassificationRule;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use InvalidArgumentException;

/**
 * Правило разбора журнала событий закупки.
 */
final readonly class EventJournalRule
{
    /**
     * @param  list<EventClassificationRule>  $classifications
     */
    public function __construct(
        public ?SelectorChain $rowSelectors = null,
        public ?SelectorChain $dateSelectors = null,
        public ?SelectorChain $descriptionSelectors = null,
        public array $classifications = [],
        public ?DateParsingRule $dateParsing = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawClassifications = $data['classifications'] ?? [];
        if (! is_array($rawClassifications)) {
            throw new InvalidArgumentException('EventJournalRule classifications must be a list.');
        }

        $classifications = [];
        foreach ($rawClassifications as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('EventJournalRule classification item must be an object.');
            }
            $classifications[] = EventClassificationRule::fromArray($item);
        }

        $rawDateParsing = RuleData::nullableObject($data, 'date_parsing');

        return new self(
            rowSelectors: RuleData::nullableSelectorChain($data, 'row_selectors'),
            dateSelectors: RuleData::nullableSelectorChain($data, 'date_selectors'),
            descriptionSelectors: RuleData::nullableSelectorChain($data, 'description_selectors'),
            classifications: $classifications,
            dateParsing: $rawDateParsing !== null ? DateParsingRule::fromArray($rawDateParsing) : null,
        );
    }

    /**
     * @return array{
     *     row_selectors: non-empty-list<string>|null,
     *     date_selectors: non-empty-list<string>|null,
     *     description_selectors: non-empty-list<string>|null,
     *     classifications: list<array{event_type: string, keywords: list<string>, pattern: string|null, priority: int}>,
     *     date_parsing: array{formats: non-empty-list<string>, timezone: string, extract_regex: string|null}|null,
     * }
     */
    public function toArray(): array
    {
        $classifications = [];
        foreach ($this->classifications as $rule) {
            $classifications[] = $rule->toArray();
        }

        return [
            'row_selectors' => $this->rowSelectors?->toArray(),
            'date_selectors' => $this->dateSelectors?->toArray(),
            'description_selectors' => $this->descriptionSelectors?->toArray(),
            'classifications' => $classifications,
            'date_parsing' => $this->dateParsing?->toArray(),
        ];
    }
}
