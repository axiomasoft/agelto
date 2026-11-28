<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\RegexPattern;
use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use InvalidArgumentException;

/**
 * Внутренний helper строгого извлечения типизированных полей из массивов правил.
 *
 * @internal
 */
final readonly class RuleData
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key, string $default): string
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nonEmptyString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $value = $data[$key];
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a non-empty string or null.', $key));
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key, int $default, int $min = 0): int
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (! is_int($value) || $value < $min) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an integer >= %d.', $key, $min));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (! is_bool($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return [];
        }

        $raw = $data[$key];
        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a list of strings.', $key));
        }

        $result = [];
        foreach ($raw as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Field "%s" items must be non-empty strings.', $key));
            }
            $result[] = trim($item);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return [];
        }

        $raw = $data[$key];
        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a string-to-string map.', $key));
        }

        $result = [];
        foreach ($raw as $mapKey => $mapValue) {
            if (! is_string($mapKey) || trim($mapKey) === '' || ! is_string($mapValue) || trim($mapValue) === '') {
                throw new InvalidArgumentException(sprintf('Field "%s" must contain non-empty string keys and values.', $key));
            }
            $result[trim($mapKey)] = trim($mapValue);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableSelectorChain(array $data, string $key): ?SelectorChain
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $raw = $data[$key];
        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a list of CSS selectors.', $key));
        }

        return SelectorChain::fromArray($raw);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableRegex(array $data, string $key, ?int $minCaptureGroups = null): ?RegexPattern
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $raw = $data[$key];
        if (! is_string($raw)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a regex pattern string or null.', $key));
        }

        return new RegexPattern($raw, $minCaptureGroups);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    public static function nullableObject(array $data, string $key): ?array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $raw = $data[$key];
        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an object/array or null.', $key));
        }

        return $raw;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function requiredObject(array $data, string $key): array
    {
        $raw = $data[$key] ?? null;
        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Field "%s" is required and must be an object/array.', $key));
        }

        return $raw;
    }
}
