<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;

/**
 * Правило извлечения списка документов из карточки закупки.
 */
final readonly class DocumentsRule
{
    /**
     * @param  list<string>  $allowedExtensions
     */
    public function __construct(
        public ?SelectorChain $containerSelectors = null,
        public ?SelectorChain $itemSelectors = null,
        public ?SelectorChain $titleSelectors = null,
        public ?SelectorChain $urlSelectors = null,
        public ?SelectorChain $hashSelectors = null,
        public ?SelectorChain $sizeSelectors = null,
        public ?SelectorChain $publishedAtSelectors = null,
        public array $allowedExtensions = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            containerSelectors: RuleData::nullableSelectorChain($data, 'container_selectors'),
            itemSelectors: RuleData::nullableSelectorChain($data, 'item_selectors'),
            titleSelectors: RuleData::nullableSelectorChain($data, 'title_selectors'),
            urlSelectors: RuleData::nullableSelectorChain($data, 'url_selectors'),
            hashSelectors: RuleData::nullableSelectorChain($data, 'hash_selectors'),
            sizeSelectors: RuleData::nullableSelectorChain($data, 'size_selectors'),
            publishedAtSelectors: RuleData::nullableSelectorChain($data, 'published_at_selectors'),
            allowedExtensions: RuleData::stringList($data, 'allowed_extensions'),
        );
    }

    /**
     * @return array{
     *     container_selectors: non-empty-list<string>|null,
     *     item_selectors: non-empty-list<string>|null,
     *     title_selectors: non-empty-list<string>|null,
     *     url_selectors: non-empty-list<string>|null,
     *     hash_selectors: non-empty-list<string>|null,
     *     size_selectors: non-empty-list<string>|null,
     *     published_at_selectors: non-empty-list<string>|null,
     *     allowed_extensions: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'container_selectors' => $this->containerSelectors?->toArray(),
            'item_selectors' => $this->itemSelectors?->toArray(),
            'title_selectors' => $this->titleSelectors?->toArray(),
            'url_selectors' => $this->urlSelectors?->toArray(),
            'hash_selectors' => $this->hashSelectors?->toArray(),
            'size_selectors' => $this->sizeSelectors?->toArray(),
            'published_at_selectors' => $this->publishedAtSelectors?->toArray(),
            'allowed_extensions' => $this->allowedExtensions,
        ];
    }
}
