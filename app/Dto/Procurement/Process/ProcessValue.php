<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Validation shared by the immutable contracts; no persistence or runtime policy. */
final class ProcessValue
{
    public static function positive(int $value): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('Expected a positive version or identifier.');
        }
    }

    public static function nonNegative(int $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Expected a non-negative sequence.');
        }
    }

    public static function text(string $value): void
    {
        if ($value === '' || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Expected nonempty UTF-8 text without NUL.');
        }
    }
}
