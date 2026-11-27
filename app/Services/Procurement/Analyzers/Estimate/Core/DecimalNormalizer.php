<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Core;

use App\Support\Numbers\DecimalNormalizer as SharedDecimalNormalizer;

/**
 * Единая нормализация decimal-строк для bcmath без прохода через float.
 */
final readonly class DecimalNormalizer
{
    /**
     * @return numeric-string|null
     */
    public static function normalize(?string $raw, bool $allowLeadingDot = false, int $maxExponent = 50): ?string
    {
        return SharedDecimalNormalizer::normalize($raw, $allowLeadingDot, $maxExponent);
    }

    public static function isWellFormedBcmath(string $value): bool
    {
        return SharedDecimalNormalizer::isWellFormedBcmath($value);
    }
}
