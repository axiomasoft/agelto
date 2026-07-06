<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use Illuminate\Support\Sleep;

/**
 * Пауза с джиттером между запросами к источнику (`02-crawling.md` §6.2).
 * Только `Sleep` (fakeable в тестах) — никогда `sleep()`/`usleep()`.
 */
final readonly class PoliteThrottle
{
    public function wait(): void
    {
        $minMs = (int) config('crawler.throttle.min_ms', 1000);
        $maxMs = (int) config('crawler.throttle.max_ms', 4000);

        Sleep::for(random_int($minMs, $maxMs))->milliseconds();
    }
}
