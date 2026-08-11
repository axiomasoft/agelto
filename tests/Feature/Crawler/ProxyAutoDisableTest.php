<?php

declare(strict_types=1);

use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Proxy\ProxyHealthStoreRepository;
use App\Services\Procurement\Proxy\CrawlerProxySettingsResolver;
use App\Services\Procurement\Proxy\ProxyCircuitBreaker;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\ProxyScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function autoDisableResolver(): ProxyResolver
{
    return new ProxyResolver(
        scoreCalculator: new ProxyScoreCalculator,
        breaker: new ProxyCircuitBreaker,
        health: new ProxyHealthStoreRepository,
        crawlerSettings: app(CrawlerProxySettingsResolver::class),
        randomSource: fn (): float => 0.0,
    );
}

test('глобальные открытия breaker отключают прокси и убирают его из выдачи', function (): void {
    config(['crawler.proxy.breaker.threshold.global' => 1, 'crawler.proxy.breaker.disable_after_openings' => 3]);
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['open_count' => 2]);
    $resolver = autoDisableResolver();

    $lease = $resolver->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
    $resolver->reportOutcome($lease, ProxyOutcome::failure(ProxyFailureReason::NetworkTimeout));

    expect($proxy->refresh()->is_active)->toBeFalse()
        ->and($proxy->disabled_at)->not->toBeNull()
        ->and($proxy->disabled_reason)->toBe(ProxyFailureReason::NetworkTimeout->value);
});

test('ручное включение сбрасывает состояние отключения', function (): void {
    $proxy = Proxy::factory()->create([
        'is_active' => false,
        'fails_count' => 2,
        'open_count' => 3,
        'open_until' => now()->addHour(),
        'disabled_at' => now(),
        'disabled_reason' => ProxyFailureReason::NetworkTimeout->value,
    ]);

    app(ProxyHealthStoreRepository::class)->reactivate($proxy);

    expect($proxy->refresh()->only(['is_active', 'fails_count', 'open_count', 'open_until', 'disabled_at', 'disabled_reason']))
        ->toBe(['is_active' => true, 'fails_count' => 0, 'open_count' => 0, 'open_until' => null, 'disabled_at' => null, 'disabled_reason' => null]);
});

test('пер-хостовое открытие breaker не отключает узел глобально', function (): void {
    config(['crawler.proxy.breaker.threshold.target' => 1]);
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    $resolver = autoDisableResolver();
    $lease = $resolver->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    $resolver->reportOutcome($lease, ProxyOutcome::failure(ProxyFailureReason::Http403));

    expect($proxy->refresh()->is_active)->toBeTrue()
        ->and($proxy->disabled_at)->toBeNull();
});

test('успех до порога обнуляет серию глобальных открытий', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 2, 'open_count' => 2]);
    $resolver = autoDisableResolver();
    $lease = $resolver->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    $resolver->reportOutcome($lease, ProxyOutcome::success());

    expect($proxy->refresh()->only(['fails_count', 'open_count', 'disabled_at']))
        ->toBe(['fails_count' => 0, 'open_count' => 0, 'disabled_at' => null]);
});
