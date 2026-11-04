<?php

declare(strict_types=1);

namespace App\Support;

final readonly class SecretRedactor
{
    public static function text(string $message): string
    {
        $message = preg_replace('~(https?://)[^\s/@]+:[^\s/@]*@~i', '$1[redacted]@', $message) ?? '';
        $message = preg_replace('~\bSQL:.*$~s', 'SQL: [redacted]', $message) ?? '';
        $message = preg_replace('~\b(Authorization\s*:\s*(?:Bearer|Basic)\s+)\S+~i', '$1[redacted]', $message) ?? '';

        return preg_replace('~(["\']?(?:password|passwd|secret|token|api[_-]?key|access_token|signature|sig|cursor)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)~i', '$1[redacted]', $message) ?? '';
    }

    /** @param array<string, mixed>|null $values
     * @return array<string, mixed>|null */
    public static function context(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (preg_match('/password|passwd|secret|token|authorization|api.?key|cookie/i', $key) === 1) {
                $values[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $values[$key] = self::text($value);
            } elseif (is_array($value)) {
                $values[$key] = self::nested($value);
            }
        }

        return $values;
    }

    /** @param array<mixed> $values
     * @return array<mixed> */
    private static function nested(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|secret|token|authorization|api.?key|cookie/i', $key) === 1) {
                $values[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $values[$key] = self::text($value);
            } elseif (is_array($value)) {
                $values[$key] = self::nested($value);
            }
        }

        return $values;
    }
}
