<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use InvalidArgumentException;
use Normalizer;

/** Shared canonicalization for provider and stored screening evidence (D33). */
final class ScreeningValueNormalizer
{
    public static function requiredText(mixed $value, int $maximumLength, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Screening {$field} must be a string.");
        }

        return self::text($value, maximumLength: $maximumLength, field: $field);
    }

    public static function optionalText(mixed $value, int $maximumLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::requiredText($value, maximumLength: $maximumLength, field: $field);
    }

    /**
     * @return list<string>
     */
    public static function tags(mixed $tags): array
    {
        if (! is_array($tags) || ! array_is_list($tags) || count($tags) > 12) {
            throw new InvalidArgumentException('Screening tags must be a list with at most 12 values.');
        }

        $normalized = [];
        $seen = [];

        foreach ($tags as $tag) {
            $value = self::requiredText($tag, maximumLength: 64, field: 'tag');
            $key = mb_strtolower($value);

            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Screening tags must be unique after case-folding.');
            }

            $seen[$key] = true;
            $normalized[] = $value;
        }

        if (array_sum(array_map(mb_strlen(...), $normalized)) > 512) {
            throw new InvalidArgumentException('Screening tags exceed the 512-code-point aggregate limit.');
        }

        return $normalized;
    }

    private static function text(string $value, int $maximumLength, string $field): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);

        if (! is_string($normalized)) {
            throw new InvalidArgumentException("Screening {$field} cannot be normalized.");
        }

        $normalized = preg_replace('/\\s+/u', ' ', $normalized);

        if ($normalized === null || preg_match('/\\p{Cc}/u', $normalized) === 1) {
            throw new InvalidArgumentException("Screening {$field} contains invalid control characters.");
        }

        $normalized = trim($normalized);
        $length = mb_strlen($normalized);

        if ($length < 1 || $length > $maximumLength) {
            throw new InvalidArgumentException("Screening {$field} length is outside the allowed range.");
        }

        return $normalized;
    }
}
