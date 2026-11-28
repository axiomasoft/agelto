<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use InvalidArgumentException;

/**
 * Транспортные и пагинационные правила извлечения листинга закупок.
 */
final readonly class ListingTransportRule
{
    public function __construct(
        public string $method = 'GET',
        public ?string $pageParam = null,
        public ?string $pageSizeParam = null,
        public int $defaultPageSize = 50,
        public ?SelectorChain $itemLinkSelectors = null,
        public ?SelectorChain $nextPageSelectors = null,
    ) {
        if ($method !== 'GET' && $method !== 'POST') {
            throw new InvalidArgumentException('ListingTransportRule method must be GET or POST.');
        }

        if ($defaultPageSize < 1) {
            throw new InvalidArgumentException('ListingTransportRule defaultPageSize must be >= 1.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: strtoupper(RuleData::string($data, 'method', 'GET')),
            pageParam: RuleData::nullableString($data, 'page_param'),
            pageSizeParam: RuleData::nullableString($data, 'page_size_param'),
            defaultPageSize: RuleData::int($data, 'default_page_size', 50, 1),
            itemLinkSelectors: RuleData::nullableSelectorChain($data, 'item_link_selectors'),
            nextPageSelectors: RuleData::nullableSelectorChain($data, 'next_page_selectors'),
        );
    }

    /**
     * @return array{
     *     method: string,
     *     page_param: string|null,
     *     page_size_param: string|null,
     *     default_page_size: int,
     *     item_link_selectors: non-empty-list<string>|null,
     *     next_page_selectors: non-empty-list<string>|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'page_param' => $this->pageParam,
            'page_size_param' => $this->pageSizeParam,
            'default_page_size' => $this->defaultPageSize,
            'item_link_selectors' => $this->itemLinkSelectors?->toArray(),
            'next_page_selectors' => $this->nextPageSelectors?->toArray(),
        ];
    }
}
