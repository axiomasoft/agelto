<?php

declare(strict_types=1);

namespace App\Support;

final class SecretValue
{
    public static function resolve(string $name, mixed $default = null): mixed
    {
        $file = self::environment("{$name}_FILE");

        if ($file !== null && is_readable($file)) {
            $value = file_get_contents($file);

            if ($value !== false && $value !== '') {
                return rtrim($value, "\r\n");
            }
        }

        return self::environment($name) ?? $default;
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
