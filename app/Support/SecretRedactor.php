<?php

declare(strict_types=1);

namespace App\Support;

final readonly class SecretRedactor
{
    private const string SENSITIVE_KEY = '/password|passwd|secret|token|authorization|api.?key|cookie|private[_-]?key|signature|sig|cursor|credential|proxy[_-]?auth|dsn|session/i';

    public static function text(string $message): string
    {
        $message = preg_replace('~([a-z][a-z0-9+.-]*://)(?:[^\s/@]+:[^\s/@]*|[^\s/@]*:[^\s/@]+)@~i', '$1[redacted]@', $message) ?? '';
        $message = preg_replace('~-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----~s', '[redacted]', $message) ?? '';
        $message = preg_replace('~\bSQL:.*$~s', 'SQL: [redacted]', $message) ?? '';
        $message = preg_replace('~\b((?:Proxy-Authorization|Authorization)\s*:\s*(?:Bearer|Basic)\s+)\S+~i', '$1[redacted]', $message) ?? '';
        $message = preg_replace('~\b((?:Bearer|Basic)\s+)(?!\[redacted\])[A-Za-z0-9._+/=-]{8,}~i', '$1[redacted]', $message) ?? '';
        $message = preg_replace('~\b((?:Proxy-Authorization|Authorization|X-Api-Key|X-Goog-Api-Key)\s*:\s*)(?!(?:Bearer|Basic)\s+\[redacted\])[^\s&,;]+~i', '$1[redacted]', $message) ?? '';
        $message = preg_replace('~\b((?:Set-Cookie|Cookie)\s*:\s*)[^\r\n;]+(?:;\s*(?!(?:Proxy-Authorization|Authorization|X-Api-Key|X-Goog-Api-Key|Set-Cookie|Cookie)\s*:)[^\r\n;]+)*~i', '$1[redacted]', $message) ?? '';

        return preg_replace('~(["\']?(?:password|passwd|secret|token|api[_-]?key|access_token|private_key|client_secret|refresh_token|id_token|session_id|dsn|signature|sig|cursor)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)~i', '$1[redacted]', $message) ?? '';
    }

    /** @param array<string, mixed>|null $values
     * @return array<string, mixed>|null */
    public static function context(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY, $key) === 1) {
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
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
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
