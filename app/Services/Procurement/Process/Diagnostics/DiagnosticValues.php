<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Diagnostics;

use LogicException;

final class DiagnosticValues
{
    public static function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new LogicException("{$key} должен быть целым числом.");
        }

        return $value;
    }

    public static function integerValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException("{$context} должен быть целым числом.");
    }

    public static function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new LogicException("{$context} должен быть строкой.");
        }

        return $value;
    }
}
