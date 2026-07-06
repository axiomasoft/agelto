<?php

declare(strict_types=1);

use App\Services\Procurement\PoliteThrottle;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;

test('спит один раз в границах конфигурации', function (): void {
    Sleep::fake();
    config(['crawler.throttle' => ['min_ms' => 1000, 'max_ms' => 4000]]);

    (new PoliteThrottle)->wait();

    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => $duration->totalMilliseconds >= 1000 && $duration->totalMilliseconds <= 4000,
        times: 1,
    );
});
