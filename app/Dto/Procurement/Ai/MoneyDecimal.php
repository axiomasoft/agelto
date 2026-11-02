<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

final readonly class MoneyDecimal
{
    public const string PATTERN = '^-?(0|[1-9][0-9]*)(\.[0-9]+)?$';

    public function __construct(public string $value, public ?string $sourceText = null)
    {
        if (strlen($value) > 80 || preg_match('/'.self::PATTERN.'/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected normalized decimal string.');
        }
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
