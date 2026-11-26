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

    public function scaleBy(string $multiplier): self
    {
        $factor = new self($multiplier);
        $scale = self::fractionLength($this->value) + self::fractionLength($factor->value);
        $product = bcmul($this->numeric(), $factor->numeric(), $scale);
        if (str_contains($product, '.')) {
            $product = rtrim(rtrim($product, '0'), '.');
        }

        return new self($product);
    }

    public static function fromThousands(string $value): self
    {
        return (new self($value))->scaleBy('1000');
    }

    private static function fractionLength(string $decimal): int
    {
        $dot = strpos($decimal, '.');

        return $dot === false ? 0 : strlen($decimal) - $dot - 1;
    }
}
