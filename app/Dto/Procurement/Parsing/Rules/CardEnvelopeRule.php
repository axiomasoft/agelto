<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;

/**
 * Правило извлечения шапки (конверта) карточки закупки.
 */
final readonly class CardEnvelopeRule
{
    /**
     * @param  list<string>  $notFoundMarkers
     */
    public function __construct(
        public ?SelectorChain $titleSelectors = null,
        public ?SelectorChain $numberSelectors = null,
        public ?SelectorChain $statusSelectors = null,
        public ?SelectorChain $priceSelectors = null,
        public ?SelectorChain $currencySelectors = null,
        public ?SelectorChain $lawSelectors = null,
        public array $notFoundMarkers = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            titleSelectors: RuleData::nullableSelectorChain($data, 'title_selectors'),
            numberSelectors: RuleData::nullableSelectorChain($data, 'number_selectors'),
            statusSelectors: RuleData::nullableSelectorChain($data, 'status_selectors'),
            priceSelectors: RuleData::nullableSelectorChain($data, 'price_selectors'),
            currencySelectors: RuleData::nullableSelectorChain($data, 'currency_selectors'),
            lawSelectors: RuleData::nullableSelectorChain($data, 'law_selectors'),
            notFoundMarkers: RuleData::stringList($data, 'not_found_markers'),
        );
    }

    /**
     * @return array{
     *     title_selectors: non-empty-list<string>|null,
     *     number_selectors: non-empty-list<string>|null,
     *     status_selectors: non-empty-list<string>|null,
     *     price_selectors: non-empty-list<string>|null,
     *     currency_selectors: non-empty-list<string>|null,
     *     law_selectors: non-empty-list<string>|null,
     *     not_found_markers: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'title_selectors' => $this->titleSelectors?->toArray(),
            'number_selectors' => $this->numberSelectors?->toArray(),
            'status_selectors' => $this->statusSelectors?->toArray(),
            'price_selectors' => $this->priceSelectors?->toArray(),
            'currency_selectors' => $this->currencySelectors?->toArray(),
            'law_selectors' => $this->lawSelectors?->toArray(),
            'not_found_markers' => $this->notFoundMarkers,
        ];
    }
}
