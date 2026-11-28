<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;

/**
 * Правило извлечения сведений о заказчике/организаторе закупки.
 */
final readonly class PartiesRule
{
    public function __construct(
        public ?SelectorChain $customerSectionSelectors = null,
        public ?SelectorChain $customerNameSelectors = null,
        public ?SelectorChain $customerInnSelectors = null,
        public ?SelectorChain $customerKppSelectors = null,
        public ?SelectorChain $customerOgrnSelectors = null,
        public ?RegexPattern $innRegex = null,
        public ?RegexPattern $kppRegex = null,
        public ?RegexPattern $ogrnRegex = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerSectionSelectors: RuleData::nullableSelectorChain($data, 'customer_section_selectors'),
            customerNameSelectors: RuleData::nullableSelectorChain($data, 'customer_name_selectors'),
            customerInnSelectors: RuleData::nullableSelectorChain($data, 'customer_inn_selectors'),
            customerKppSelectors: RuleData::nullableSelectorChain($data, 'customer_kpp_selectors'),
            customerOgrnSelectors: RuleData::nullableSelectorChain($data, 'customer_ogrn_selectors'),
            innRegex: RuleData::nullableRegex($data, 'inn_regex'),
            kppRegex: RuleData::nullableRegex($data, 'kpp_regex'),
            ogrnRegex: RuleData::nullableRegex($data, 'ogrn_regex'),
        );
    }

    /**
     * @return array{
     *     customer_section_selectors: non-empty-list<string>|null,
     *     customer_name_selectors: non-empty-list<string>|null,
     *     customer_inn_selectors: non-empty-list<string>|null,
     *     customer_kpp_selectors: non-empty-list<string>|null,
     *     customer_ogrn_selectors: non-empty-list<string>|null,
     *     inn_regex: string|null,
     *     kpp_regex: string|null,
     *     ogrn_regex: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'customer_section_selectors' => $this->customerSectionSelectors?->toArray(),
            'customer_name_selectors' => $this->customerNameSelectors?->toArray(),
            'customer_inn_selectors' => $this->customerInnSelectors?->toArray(),
            'customer_kpp_selectors' => $this->customerKppSelectors?->toArray(),
            'customer_ogrn_selectors' => $this->customerOgrnSelectors?->toArray(),
            'inn_regex' => $this->innRegex?->pattern,
            'kpp_regex' => $this->kppRegex?->pattern,
            'ogrn_regex' => $this->ogrnRegex?->pattern,
        ];
    }
}
