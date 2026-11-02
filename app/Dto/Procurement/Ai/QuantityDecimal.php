<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

final readonly class QuantityDecimal
{
    public function __construct(public string $value, public ?string $sourceText = null)
    {
        new MoneyDecimal($value);
    }

    /** @return numeric-string */
    public function numeric(): string
    {
        if (! is_numeric($this->value)) {
            throw new \LogicException('Invalid decimal invariant.');
        }

        return $this->value;
    }
}
