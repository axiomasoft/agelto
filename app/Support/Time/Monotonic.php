<?php

declare(strict_types=1);

namespace App\Support\Time;

use LogicException;

/**
 * Typed monotonic clock. `hrtime(true)` is declared `int|float` because it degrades to a float
 * on 32-bit builds, where nanosecond deadlines silently lose precision. The runtime images are
 * 64-bit only, so a float here is an environment error, not a value to compute with.
 */
final class Monotonic
{
    public static function nanoseconds(): int
    {
        $nanoseconds = hrtime(true);

        return is_int($nanoseconds)
            ? $nanoseconds
            : throw new LogicException('hrtime() returned a float; a 64-bit PHP build is required.');
    }

    public static function seconds(): float
    {
        return self::nanoseconds() / 1_000_000_000;
    }
}
