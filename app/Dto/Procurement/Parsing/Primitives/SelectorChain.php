<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Primitives;

use InvalidArgumentException;

/**
 * Упорядоченная цепочка резервных CSS-селекторов (от наиболее специфичного к запасному).
 */
final readonly class SelectorChain
{
    /**
     * @param  non-empty-list<CssSelector>  $selectors
     */
    public function __construct(
        public array $selectors,
    ) {}

    /**
     * @param  array<array-key, mixed>  $items
     */
    public static function fromArray(array $items): self
    {
        $selectors = [];
        foreach ($items as $item) {
            if ($item instanceof CssSelector) {
                $selectors[] = $item;
            } elseif (is_string($item)) {
                $selectors[] = new CssSelector($item);
            } else {
                throw new InvalidArgumentException('Selector chain items must be non-empty strings or CssSelector instances.');
            }
        }

        if ($selectors === []) {
            throw new InvalidArgumentException('Selector chain must contain at least one selector.');
        }

        return new self($selectors);
    }

    /**
     * @return non-empty-list<string>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->selectors as $selector) {
            $result[] = $selector->selector;
        }

        return $result;
    }
}
