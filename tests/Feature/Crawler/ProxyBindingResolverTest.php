<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('резолвер профиля возвращает только явно привязанные узлы', function (): void {
    $bound = Proxy::factory()->create();
    Proxy::factory()->create();
    $crawler = Crawler::factory()->withProxies($bound)->create();

    expect(app(ProxyBindingResolver::class)->forCrawler($crawler))->toBe([$bound->id]);
});

test('резолвер стадии объединяет только наборы активных proxy-профилей реестра закупки', function (): void {
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->for($registry)->create();
    $first = Proxy::factory()->create();
    $second = Proxy::factory()->create();
    $ignoredInactive = Proxy::factory()->create();
    $ignoredDirect = Proxy::factory()->create();
    $otherRegistry = Registry::factory()->create();
    $ignoredOtherRegistry = Proxy::factory()->create();

    Crawler::factory()->for($registry)->withProxies($first)->create();
    Crawler::factory()->for($registry)->withProxies($second)->create();
    Crawler::factory()->for($registry)->withProxies($ignoredInactive)->create(['is_active' => false]);
    Crawler::factory()->for($registry)->create()->proxies()->sync([$ignoredDirect->id]);
    Crawler::withoutEvents(static fn (): Crawler => Crawler::factory()
        ->for($otherRegistry)
        ->withProxies($ignoredOtherRegistry)
        ->create());

    $requirements = app(ProxyBindingResolver::class)->requirementsForProcurement($procurement->id);

    expect($requirements->crawlerMode)->toBe(CrawlerProxyMode::Profile)
        ->and($requirements->proxyIds)->toEqualCanonicalizing([$first->id, $second->id])
        ->and($requirements->proxyIds)->not->toContain($ignoredOtherRegistry->id);
});

test('резолвер стадии выбирает default без profile и direct только при явном opt-out всех профилей', function (): void {
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->for($registry)->create();
    Crawler::factory()->for($registry)->create(['proxy_mode' => CrawlerProxyMode::Default]);

    $default = app(ProxyBindingResolver::class)->requirementsForProcurement($procurement->id);

    expect($default->crawlerMode)->toBe(CrawlerProxyMode::Default)
        ->and($default->proxyIds)->toBeNull();

    Crawler::query()->where('registry_id', $registry->id)->update(['proxy_mode' => CrawlerProxyMode::Direct->value]);

    $direct = app(ProxyBindingResolver::class)->requirementsForProcurement($procurement->id);

    expect($direct->crawlerMode)->toBe(CrawlerProxyMode::Direct)
        ->and($direct->proxyIds)->toBeNull();
});
