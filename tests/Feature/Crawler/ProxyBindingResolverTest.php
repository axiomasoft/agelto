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
    $registry = Registry::factory()->create();
    $procurement = Procurement::factory()->for($registry)->create();
    $first = Proxy::factory()->create();
    $second = Proxy::factory()->create();
    $ignoredInactive = Proxy::factory()->create();
    $ignoredDirect = Proxy::factory()->create();
    $ignoredOtherRegistry = Proxy::factory()->create();

    Crawler::factory()->for($registry)->withProxies($first)->create();
    Crawler::factory()->for($registry)->withProxies($second)->create();
    Crawler::factory()->for($registry)->withProxies($ignoredInactive)->create(['is_active' => false]);
    Crawler::factory()->for($registry)->create()->proxies()->sync([$ignoredDirect->id]);
    Crawler::factory()->withProxies($ignoredOtherRegistry)->create();

    $requirements = app(ProxyBindingResolver::class)->requirementsForProcurement($procurement->id);

    expect($requirements->crawlerMode)->toBe(CrawlerProxyMode::Profile)
        ->and($requirements->proxyIds)->toEqualCanonicalizing([$first->id, $second->id]);
});
