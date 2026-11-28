<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use InvalidArgumentException;

/**
 * Правило структурной навигации по секциям и парам ключ-значение в DOM карточки.
 */
final readonly class CardDomRule
{
    /**
     * @param  array<string, SelectorChain>  $sectionSelectors
     */
    public function __construct(
        public array $sectionSelectors = [],
        public ?SelectorChain $fieldRowsSelectors = null,
        public ?SelectorChain $labelSelectors = null,
        public ?SelectorChain $valueSelectors = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawSections = $data['section_selectors'] ?? [];
        if (! is_array($rawSections)) {
            throw new InvalidArgumentException('CardDomRule section_selectors must be an object.');
        }

        $sectionSelectors = [];
        foreach ($rawSections as $sectionKey => $chain) {
            if (! is_string($sectionKey) || trim($sectionKey) === '' || ! is_array($chain)) {
                throw new InvalidArgumentException('CardDomRule section_selectors must map non-empty keys to selector chains.');
            }
            $sectionSelectors[trim($sectionKey)] = SelectorChain::fromArray($chain);
        }

        return new self(
            sectionSelectors: $sectionSelectors,
            fieldRowsSelectors: RuleData::nullableSelectorChain($data, 'field_rows_selectors'),
            labelSelectors: RuleData::nullableSelectorChain($data, 'label_selectors'),
            valueSelectors: RuleData::nullableSelectorChain($data, 'value_selectors'),
        );
    }

    /**
     * @return array{
     *     section_selectors: array<string, non-empty-list<string>>,
     *     field_rows_selectors: non-empty-list<string>|null,
     *     label_selectors: non-empty-list<string>|null,
     *     value_selectors: non-empty-list<string>|null,
     * }
     */
    public function toArray(): array
    {
        $sections = [];
        foreach ($this->sectionSelectors as $key => $chain) {
            $sections[$key] = $chain->toArray();
        }

        return [
            'section_selectors' => $sections,
            'field_rows_selectors' => $this->fieldRowsSelectors?->toArray(),
            'label_selectors' => $this->labelSelectors?->toArray(),
            'value_selectors' => $this->valueSelectors?->toArray(),
        ];
    }
}
