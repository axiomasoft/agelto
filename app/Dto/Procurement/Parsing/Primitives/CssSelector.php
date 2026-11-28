<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Primitives;

use InvalidArgumentException;

/**
 * Валидированный CSS-селектор для извлечения элементов DOM.
 */
final readonly class CssSelector
{
    public string $selector;

    public function __construct(string $selector)
    {
        $trimmed = trim($selector);
        if ($trimmed === '' || strlen($trimmed) > 512) {
            throw new InvalidArgumentException('CSS selector must be non-empty and at most 512 bytes.');
        }

        $this->selector = $trimmed;
    }

    public static function fromString(string $selector): self
    {
        return new self($selector);
    }

    public function toString(): string
    {
        return $this->selector;
    }
}
