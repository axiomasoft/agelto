<?php

declare(strict_types=1);

use Tests\Support\Postgres\ConcurrentTransactions;

test('два одновременных глобальных отказа учитываются оба и один раз отключают узел', function (): void {
    $fixture = concurrentProxyOutcomeFixture();

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => concurrentProxyOutcomeWorker(
                proxyId: $fixture['proxy_id'],
                outcome: '\\App\\Dto\\Procurement\\Proxy\\ProxyOutcome::failure(\\App\\Enums\\Procurement\\Proxy\\ProxyFailureReason::NetworkTimeout)',
            ),
            'second' => concurrentProxyOutcomeWorker(
                proxyId: $fixture['proxy_id'],
                outcome: '\\App\\Dto\\Procurement\\Proxy\\ProxyOutcome::failure(\\App\\Enums\\Procurement\\Proxy\\ProxyFailureReason::NetworkTimeout)',
            ),
        ]);
        $state = concurrentProxyOutcomeState(proxyId: $fixture['proxy_id']);

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($state['proxy']['fails_count'])->toBe(0)
            ->and($state['proxy']['open_count'])->toBe(1)
            ->and($state['proxy']['is_active'])->toBeFalse()
            ->and($state['target']['consecutive_failures'])->toBe(0)
            ->and($state['disable_logs'])->toBe(1);
    } finally {
        concurrentProxyOutcomeCleanup(proxyId: $fixture['proxy_id']);
    }
});

test('два одновременных успешных замера входят в EWMA при alpha 0.3', function (): void {
    $fixture = concurrentProxyOutcomeFixture();

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => concurrentProxyOutcomeWorker(
                proxyId: $fixture['proxy_id'],
                outcome: '\\App\\Dto\\Procurement\\Proxy\\ProxyOutcome::success(latencyMs: 200)',
            ),
            'second' => concurrentProxyOutcomeWorker(
                proxyId: $fixture['proxy_id'],
                outcome: '\\App\\Dto\\Procurement\\Proxy\\ProxyOutcome::success(latencyMs: 300)',
            ),
        ]);
        $state = concurrentProxyOutcomeState(proxyId: $fixture['proxy_id']);

        $alpha = 0.3;
        $expectedLatencies = [
            serialLatencyEwma(initial: 100, first: 200, second: 300, alpha: $alpha),
            serialLatencyEwma(initial: 100, first: 300, second: 200, alpha: $alpha),
        ];

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and($state['target']['latency_ms_avg'])->toBeIn($expectedLatencies)
            ->and($state['target']['success_ratio'])->toBeGreaterThan(0.75)
            ->and($state['target']['success_ratio'])->toBeLessThan(0.76);
    } finally {
        concurrentProxyOutcomeCleanup(proxyId: $fixture['proxy_id']);
    }
});

test('смешанные global и target outcomes не инвертируют порядок блокировок', function (): void {
    $fixture = concurrentProxyOutcomeFixture();

    try {
        $workers = ConcurrentTransactions::concurrently([
            'global' => concurrentProxyOutcomeWorker(
                proxyId: $fixture['proxy_id'],
                outcome: '\\App\\Dto\\Procurement\\Proxy\\ProxyOutcome::failure(\\App\\Enums\\Procurement\\Proxy\\ProxyFailureReason::NetworkTimeout)',
            ),
            'target' => concurrentProxyOutcomeWorker(
                proxyId: $fixture['proxy_id'],
                outcome: '\\App\\Dto\\Procurement\\Proxy\\ProxyOutcome::failure(\\App\\Enums\\Procurement\\Proxy\\ProxyFailureReason::Http403)',
            ),
        ]);
        $state = concurrentProxyOutcomeState(proxyId: $fixture['proxy_id']);

        expect($workers['global']['backend_pid'])->not->toBe($workers['target']['backend_pid'])
            ->and($state['proxy']['fails_count'])->toBe(1)
            ->and($state['target']['consecutive_failures'])->toBe(1)
            ->and($state['disable_logs'])->toBe(0);
    } finally {
        concurrentProxyOutcomeCleanup(proxyId: $fixture['proxy_id']);
    }
});

/**
 * @return array{proxy_id: int}
 */
function concurrentProxyOutcomeFixture(): array
{
    return ConcurrentTransactions::execute(<<<'PHP'
$proxy = \App\Models\Procurement\Crawler\Proxy::factory()->create();
\App\Models\Procurement\Crawler\ProxyTarget::factory()->create([
    'proxy_id' => $proxy->id,
    'target' => 'proxy-outcome.example',
    'success_ratio' => 0.5,
    'latency_ms_avg' => 100,
]);

return ['proxy_id' => $proxy->id];
PHP);
}

function concurrentProxyOutcomeWorker(int $proxyId, string $outcome): string
{
    return <<<PHP
config([
    'crawler.proxy.breaker.threshold.global' => 2,
    'crawler.proxy.breaker.disable_after_openings' => 1,
]);
\$resolver = new \App\Services\Procurement\Proxy\ProxyResolver(
    scoreCalculator: new \App\Services\Procurement\Proxy\ProxyScoreCalculator,
    breaker: new \App\Services\Procurement\Proxy\ProxyCircuitBreaker,
    health: new \App\Repositories\Procurement\Proxy\ProxyHealthStoreRepository,
    crawlerSettings: app(\App\Services\Procurement\Proxy\CrawlerProxySettingsResolver::class),
    operationLogs: new \App\Services\Procurement\OperationLogWriter,
);
\$lease = \App\Dto\Procurement\Proxy\ProxyLease::forProxy(
    proxyId: {$proxyId},
    host: '127.0.0.1',
    port: 8080,
    username: null,
    password: null,
    scope: \App\Enums\Procurement\Proxy\ProxyScope::CrawlListing,
    target: 'proxy-outcome.example',
);
\$resolver->reportOutcome(lease: \$lease, outcome: {$outcome});

return ['backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
PHP;
}

/**
 * @return array{proxy: array{fails_count: int, open_count: int, is_active: bool}, target: array{consecutive_failures: int, latency_ms_avg: ?int, success_ratio: float}, disable_logs: int}
 */
function concurrentProxyOutcomeState(int $proxyId): array
{
    return ConcurrentTransactions::execute(<<<PHP
\$proxy = \App\Models\Procurement\Crawler\Proxy::query()->findOrFail({$proxyId});
\$target = \App\Models\Procurement\Crawler\ProxyTarget::query()
    ->where('proxy_id', {$proxyId})
    ->where('target', 'proxy-outcome.example')
    ->sole();

return [
    'proxy' => [
        'fails_count' => \$proxy->fails_count,
        'open_count' => \$proxy->open_count,
        'is_active' => \$proxy->is_active,
    ],
    'target' => [
        'consecutive_failures' => \$target->consecutive_failures,
        'latency_ms_avg' => \$target->latency_ms_avg,
        'success_ratio' => \$target->success_ratio,
    ],
    'disable_logs' => \App\Models\Procurement\OperationLog::query()
        ->where('entity_type', 'proxy')
        ->where('entity_id', {$proxyId})
        ->where('message', 'Прокси отключён автоматически')
        ->count(),
];
PHP);
}

function concurrentProxyOutcomeCleanup(int $proxyId): void
{
    ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\OperationLog::query()
    ->where('entity_type', 'proxy')
    ->where('entity_id', {$proxyId})
    ->delete();
\App\Models\Procurement\Crawler\ProxyTarget::query()->where('proxy_id', {$proxyId})->delete();
\App\Models\Procurement\Crawler\Proxy::query()->whereKey({$proxyId})->delete();

return ['cleaned' => true];
PHP);
}

function serialLatencyEwma(int $initial, int $first, int $second, float $alpha): int
{
    $afterFirst = (int) round($initial * (1 - $alpha) + $first * $alpha);

    return (int) round($afterFirst * (1 - $alpha) + $second * $alpha);
}
