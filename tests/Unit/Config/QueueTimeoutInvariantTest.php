<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\StageQueueMap;

test('скачивание и ревалидация имеют Horizon consumer с достаточным timeout', function (): void {
    $route = app(StageQueueMap::class)->forStage(StageCode::Download);
    expect($route->jobTimeout)->toBe(320)->and($route->horizonTimeout)->toBe(340)
        ->and($route->retryAfter)->toBeGreaterThan($route->horizonTimeout)->and($route->leaseSeconds)->toBeGreaterThan($route->retryAfter);
});

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

test('canonical AI stages share one queue map and preserve slice job supervisor retry and lease ordering', function (): void {
    $map = app(StageQueueMap::class);
    foreach ([StageCode::Screening, StageCode::Extraction] as $stage) {
        $route = $map->forStage($stage);
        expect($route->jobTimeout)->toBeLessThan($route->horizonTimeout)
            ->and($route->horizonTimeout)->toBeLessThan($route->retryAfter)
            ->and($route->retryAfter)->toBeLessThan($route->leaseSeconds)
            ->and($route->queue)->toBe('process-ai');
    }
    expect(config('horizon.defaults'))->not->toHaveKeys(['supervisor-ai-screening', 'supervisor-ai-extraction', 'supervisor-ai-shadow']);
});

test('long lane сохраняет обе timeout-цепочки и documented stop_grace_period (инвариант D117)', function (): void {
    expect(config('horizon.defaults.supervisor-crawl.timeout'))->toBe(300)
        ->and(config('queue.connections.redis.retry_after'))->toBe(420)
        ->and(config('horizon.defaults.supervisor-crawl-long.timeout'))->toBe(3600)
        ->and(config('queue.connections.redis_crawl_long.retry_after'))->toBe(3900);

    $longestSupervisorTimeout = collect((array) config('horizon.defaults'))->max('timeout');

    // Compose не читается из PHP-рантайма; фиксируем согласованный D117 default, чтобы будущая
    // правка Horizon не превысила `stop_grace_period` в dev/prod (4200s).
    $documentedStopGracePeriodSeconds = 4200;

    expect($longestSupervisorTimeout)->toBeLessThan($documentedStopGracePeriodSeconds);
});
