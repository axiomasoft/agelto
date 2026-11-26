<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

final readonly class ExtractionArithmeticPolicy
{
    /** @var numeric-string */
    public string $roundingTolerance;

    /** @var numeric-string */
    public string $coefficientTolerance;

    public function __construct(string $roundingTolerance, string $coefficientTolerance)
    {
        $this->roundingTolerance = self::tolerance($roundingTolerance);
        $this->coefficientTolerance = self::tolerance($coefficientTolerance);
    }

    /** @return array{rounding_tolerance: numeric-string, coefficient_tolerance: numeric-string} */
    public function fields(): array
    {
        return ['rounding_tolerance' => $this->roundingTolerance, 'coefficient_tolerance' => $this->coefficientTolerance];
    }

    /** @return numeric-string */
    private static function tolerance(string $value): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Extraction arithmetic tolerance must be a non-negative decimal in cents.');
        }

        return (new MoneyDecimal($value))->numeric();
    }
}
