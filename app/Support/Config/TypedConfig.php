<?php

declare(strict_types=1);

namespace App\Support\Config;

use LogicException;

/**
 * Типизированное чтение конфигурации с fail-fast валидацией при неверных значениях.
 */
final readonly class TypedConfig
{
    public static function positiveInt(string $key, ?int $default = null): int
    {
        $value = $default === null ? config($key) : config($key, $default);

        return self::requirePositiveInt($value, $key);
    }

    public static function nonNegativeInt(string $key, ?int $default = null): int
    {
        $value = $default === null ? config($key) : config($key, $default);

        if (! is_int($value) || $value < 0) {
            throw new LogicException("{$key} должен быть неотрицательным целым числом.");
        }

        return $value;
    }

    public static function positiveFloat(string $key, ?float $default = null): float
    {
        $value = $default === null ? config($key) : config($key, $default);

        if ((! is_float($value) && ! is_int($value)) || $value <= 0) {
            throw new LogicException("{$key} должен быть положительным числом.");
        }

        return (float) $value;
    }

    public static function nonEmptyString(string $key, ?string $default = null): string
    {
        $value = $default === null ? config($key) : config($key, $default);

        if (! is_string($value) || $value === '') {
            throw new LogicException("{$key} должен быть непустой строкой.");
        }

        return $value;
    }

    public static function requirePositiveInt(mixed $value, string $label): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new LogicException("{$label} должен быть положительным целым числом.");
        }

        return $value;
    }
}
