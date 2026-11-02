<?php

declare(strict_types=1);

namespace App\Services\Llm;

/** Presentation only: four decimal places, from an explicitly denominated integer microcurrency. */
final class ReportMoney
{
    public static function format(int $micros, string $currency): string
    {
        if ($micros < 0 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Invalid reported monetary amount.');
        }
        $rounded = bcdiv(bcadd((string) $micros, '50'), '100', 0);

        return ($currency === 'USD' ? '$' : $currency.' ').bcdiv($rounded, '10000', 4);
    }
}
