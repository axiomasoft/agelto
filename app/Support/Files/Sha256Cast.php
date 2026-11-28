<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Hex at the Eloquent boundary; PostgreSQL stores exactly 32 bytes.
 *
 * @implements CastsAttributes<string, string>
 */
final class Sha256Cast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (is_resource($value)) {
            rewind($value);
        }
        $bytes = is_resource($value) ? stream_get_contents($value) : $value;
        if (is_string($bytes) && str_starts_with($bytes, '\\x')) {
            $bytes = hex2bin(substr($bytes, 2));
        }
        if (! is_string($bytes) || strlen($bytes) !== 32) {
            throw new \UnexpectedValueException('Expected a 32-byte SHA-256.');
        }

        return bin2hex($bytes);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Expected a lowercase SHA-256 hex digest.');
        }

        return '\\x'.$value;
    }
}
