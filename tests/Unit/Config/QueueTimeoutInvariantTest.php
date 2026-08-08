<?php

declare(strict_types=1);

test('таймаут каждого horizon-супервизора строго меньше retry_after его connection (инварианты D26 и D117)', function (): void {
    foreach ((array) config('horizon.defaults') as $name => $supervisor) {
        $connection = $supervisor['connection'];
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        expect($supervisor['timeout'])->toBeLessThan(
            $retryAfter,
            "Супервизор \"{$name}\" ({$connection}): timeout={$supervisor['timeout']}s ≥ retry_after={$retryAfter}s — ".
            'Redis сочтёт job осиротевшим и передиспатчит его другому воркеру, пока первый ещё легитимно работает (D26).',
        );
    }
});

test('checkpoint_seconds извлечения строго меньше timeout supervisor-ai-extraction (инвариант D31)', function () {
    $checkpointSeconds = (int) config('ai.extraction.checkpoint_seconds');
    $timeout = (int) config('horizon.defaults.supervisor-ai-extraction.timeout');

    expect($checkpointSeconds)->toBeLessThan(
        $timeout,
        "checkpoint_seconds={$checkpointSeconds}s ≥ supervisor-ai-extraction.timeout={$timeout}s — ".
        'self-requeue не успеет сработать раньше, чем Horizon убьёт job по таймауту (D31).',
    );
});

test('long lane сохраняет обе timeout-цепочки и documented stop_grace_period (инвариант D117)', function (): void {
    expect(config('horizon.defaults.supervisor-crawl.timeout'))->toBe(300)
        ->and(config('queue.connections.redis.retry_after'))->toBe(360)
        ->and(config('horizon.defaults.supervisor-crawl-long.timeout'))->toBe(3600)
        ->and(config('queue.connections.redis_crawl_long.retry_after'))->toBe(3900);

    $longestSupervisorTimeout = collect((array) config('horizon.defaults'))->max('timeout');

    // Compose не читается из PHP-рантайма; фиксируем согласованный D117 default, чтобы будущая
    // правка Horizon не превысила `stop_grace_period` в dev/prod (4200s).
    $documentedStopGracePeriodSeconds = 4200;

    expect($longestSupervisorTimeout)->toBeLessThan($documentedStopGracePeriodSeconds);
});
