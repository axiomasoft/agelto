<?php

declare(strict_types=1);

namespace App\Support\Numbers;

/**
 * Единая нормализация decimal-строк для bcmath без прохода через float:
 * очищает пробелы/NBSP/узкий NBSP, заменяет запятую точкой, снимает ведущий `+`,
 * разворачивает научную нотацию с ограничением порядка экспоненты и проверяет
 * строгий формат `/^-?\d+(?:\.\d+)?$/D`.
 */
final readonly class DecimalNormalizer
{
    /**
     * @return numeric-string|null
     */
    public static function normalize(?string $raw, bool $allowLeadingDot = false, int $maxExponent = 50): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === '-' || $trimmed === '+') {
            return null;
        }

        $withoutSpaces = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $trimmed);
        $dotted = str_replace(',', '.', $withoutSpaces);

        if (str_starts_with($dotted, '+')) {
            $dotted = substr($dotted, 1);
        }

        if ($allowLeadingDot) {
            if (str_starts_with($dotted, '.')) {
                $dotted = '0'.$dotted;
            } elseif (str_starts_with($dotted, '-.')) {
                $dotted = '-0.'.substr($dotted, 2);
            }

            $dotted = (string) preg_replace('/^(-?\d+)\.(?=[eE]|$)/D', '$1', $dotted);
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/D', $dotted, $matches) === 1) {
            $expDigits = ltrim($matches[4], '+-0');

            if (strlen($expDigits) > 6) {
                return null;
            }

            $exponent = (int) $matches[4];

            if (abs($exponent) > $maxExponent) {
                return null;
            }

            $sign = $matches[1];
            $intPart = $matches[2];
            $fracPart = $matches[3];
            $digits = $intPart.$fracPart;
            $pointPosition = strlen($intPart) + $exponent;

            if ($pointPosition <= 0) {
                $digits = str_repeat('0', -$pointPosition + 1).$digits;
                $pointPosition = 1;
            } elseif ($pointPosition > strlen($digits)) {
                $digits .= str_repeat('0', $pointPosition - strlen($digits));
            }

            $integerResult = substr($digits, 0, $pointPosition);
            $fractionResult = substr($digits, $pointPosition);
            $dotted = $sign.$integerResult.($fractionResult !== '' ? ".{$fractionResult}" : '');
        }

        if (! self::isWellFormedBcmath($dotted)) {
            return null;
        }

        /** @var numeric-string&non-empty-string $dotted */
        return $dotted;
    }

    public static function isWellFormedBcmath(string $value): bool
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1;
    }
}
