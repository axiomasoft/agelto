<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

final readonly class ExtractionArithmeticPolicy
{
    /** @var numeric-string */
    public string $roundingTolerance;

    /** @var numeric-string */
    public string $coefficientTolerance;

    /** @var numeric-string */
    public string $currencyUnitScale;

    public function __construct(string $roundingTolerance, string $coefficientTolerance, string $currencyUnitScale = '1')
    {
        $this->roundingTolerance = self::tolerance($roundingTolerance);
        $this->coefficientTolerance = self::tolerance($coefficientTolerance);
        if (strlen($currencyUnitScale) > 80 || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $currencyUnitScale) !== 1) {
            throw new \InvalidArgumentException('Currency unit scale must be a positive decimal string.');
        }
        $scale = (new MoneyDecimal($currencyUnitScale))->numeric();
        if (bccomp($scale, '0', self::fractionLength($scale)) <= 0) {
            throw new \InvalidArgumentException('Currency unit scale must be a positive decimal string.');
        }
        $this->currencyUnitScale = $scale;
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

    private static function fractionLength(string $decimal): int
    {
        $dot = strpos($decimal, '.');

        return $dot === false ? 0 : strlen($decimal) - $dot - 1;
    }
}
