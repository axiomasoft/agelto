<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\StageQueueMap;

test('скачивание и ревалидация имеют Horizon consumer с достаточным timeout', function (): void {
    $route = app(StageQueueMap::class)->forStage(StageCode::Download);
    expect($route->jobTimeout)->toBe(320)->and($route->horizonTimeout)->toBe(340)
        ->and($route->retryAfter)->toBeGreaterThan($route->horizonTimeout)->and($route->leaseSeconds)->toBeGreaterThan($route->retryAfter);
});

test('таймаут каждого horizon-супервизора строго меньше retry_after его connection', function (): void {
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

test('final Horizon consumers and actual Compose grace match the Process timeout bounds', function (): void {
    $supervisors = config('horizon.defaults');
    expect(array_keys($supervisors))->toEqualCanonicalizing([
        'supervisor-1', 'supervisor-process-listing', 'supervisor-process-acquisition',
        'supervisor-process-download', 'supervisor-process-prepare', 'supervisor-process-ai',
        'supervisor-process-publication',
    ]);
    expect(config('queue.connections'))->not->toHaveKeys(['redis_prepare', 'redis_crawl_long']);
    foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $path) {
        $compose = file_get_contents(base_path($path));
        expect($compose)->toContain('370s')->not->toContain('4200s');
    }
    expect(collect($supervisors)->max('timeout'))->toBeLessThan(370);

});
