<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

/** Exact nonnegative numeric(24,8) currency amount. Never accepts float or exponent notation. */
final readonly class FinancialAmount
{
    /** @var numeric-string */
    public string $value;

    public function __construct(string $value)
    {
        if (! is_numeric($value) || preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,8})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected exact nonnegative numeric(24,8) money.');
        }
        $this->value = bcadd($value, '0', 8);
    }

    /** @return numeric-string */
    public static function normalize(string $value): string
    {
        return (new self($value))->value;
    }
}
