<?php

declare(strict_types=1);

use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyRouteSource;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyTarget;
use App\Repositories\Procurement\Proxy\ProxyHealthStoreRepository;
use App\Services\Procurement\Proxy\CrawlerProxySettingsResolver;
use App\Services\Procurement\Proxy\ProxyCircuitBreaker;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\ProxyScoreCalculator;
use App\Settings\Procurement\CrawlerProxySettings;

function makeResolver(?Closure $randomSource = null, ?CrawlerProxySettingsResolver $crawlerSettings = null): ProxyResolver
{
    return new ProxyResolver(
        scoreCalculator: new ProxyScoreCalculator,
        breaker: new ProxyCircuitBreaker,
        health: new ProxyHealthStoreRepository,
        randomSource: $randomSource ?? fn (): float => 0.0,
        crawlerSettings: $crawlerSettings ?? app(CrawlerProxySettingsResolver::class),
    );
}

test('score использует только числовую конфигурацию весов и точек отсчёта', function (): void {
    config([
        'crawler.proxy.resolver.weights' => ['success' => 1.0, 'latency' => 0.5, 'streak' => 0.25],
        'crawler.proxy.resolver.latency_ref_ms' => 100,
        'crawler.proxy.resolver.streak_ref' => 4,
    ]);

    $score = (new ProxyScoreCalculator)->score([
        'success_ratio' => 1.0,
        'latency_ms_avg' => 50,
        'fails_count' => 2,
    ]);

    expect($score)->toBe(0.625);
});

test('score отклоняет некорректную конфигурацию', function (): void {
    config(['crawler.proxy.resolver.latency_ref_ms' => '5000']);

    expect(fn (): float => (new ProxyScoreCalculator)->score([
        'success_ratio' => 1.0,
        'latency_ms_avg' => null,
        'fails_count' => 0,
    ]))->toThrow(LogicException::class, 'должен быть положительным числом');
});

test('узел из запрещённой по фильтру страны не попадает в кандидаты', function (): void {
    $allowed = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    Proxy::factory()->country('KZ')->withScopes([ProxyScope::CrawlListing])->create();

    $lease = makeResolver()->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(country: 'RU'),
    );

    expect($lease->proxyId)->toBe($allowed->id);
});

test('требование страны без совпадений даёт NoProxyAvailableException', function (): void {
    Proxy::factory()->country('KZ')->withScopes([ProxyScope::CrawlListing])->create();

    makeResolver()->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(country: 'RU'),
    );
})->throws(NoProxyAvailableException::class);

test('явная привязка не позволяет выдать подходящий, но не привязанный узел', function (): void {
    $bound = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();

    $lease = makeResolver()->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(proxyIds: [$bound->id]),
    );

    expect($lease->proxyId)->toBe($bound->id);
});

test('гео-гейт применяется до явной привязки и не выдаёт запрещённую страну из набора', function (): void {
    $deProxy = Proxy::factory()->country('DE')->withScopes([ProxyScope::CrawlListing])->create();

    makeResolver()->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(country: 'RU', proxyIds: [$deProxy->id]),
    );
})->throws(NoProxyAvailableException::class);

test('открытый breaker на одной цели не исключает узел на другой цели', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create();
    ProxyTarget::factory()->open()->create(['proxy_id' => $proxy->id, 'target' => 'a.example']);

    $leaseOnFreeTarget = makeResolver()->resolve(scope: ProxyScope::CrawlDetail, target: 'b.example');
    expect($leaseOnFreeTarget->proxyId)->toBe($proxy->id);

    makeResolver()->resolve(scope: ProxyScope::CrawlDetail, target: 'a.example');
})->throws(NoProxyAvailableException::class);

test('half-open пропускает ровно одну пробу', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create([
        'open_count' => 1,
        'open_until' => now()->subMinute(),
    ]);

    $lease = makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
    expect($lease->proxyId)->toBe($proxy->id);

    makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
})->throws(NoProxyAvailableException::class);

test('tie-breaker по числу отказов работает только при равном score', function (): void {
    config(['crawler.proxy.resolver.top_k' => 1, 'crawler.proxy.resolver.streak_ref' => 5]);

    // Оба fails_count выше streak_ref — streak-штраф насыщен до 1.0 у обоих: score равен,
    // tie-break решает fails_count (меньше = лучше), а не score.
    $fewerFailures = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 10]);
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 20]);

    $lease = makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    expect($lease->proxyId)->toBe($fewerFailures->id);
});

test('пустой пул даёт NoProxyAvailableException', function (): void {
    makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
})->throws(NoProxyAvailableException::class);

test('default-режим выдаёт только сохранённый узел и помечает его источником default', function (): void {
    $default = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    $settings = new CrawlerProxySettings;
    $settings->defaultProxyId = $default->id;
    $settings->save();

    $lease = makeResolver(crawlerSettings: app(CrawlerProxySettingsResolver::class))->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(crawlerMode: CrawlerProxyMode::Default),
    );

    expect($lease->proxyId)->toBe($default->id)
        ->and($lease->source)->toBe(ProxyRouteSource::Default);
});

test('configured unavailable default не переходит на direct или другой узел', function (): void {
    $default = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create([
        'open_until' => now()->addMinute(),
    ]);
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    $settings = new CrawlerProxySettings;
    $settings->defaultProxyId = $default->id;
    $settings->save();

    makeResolver(crawlerSettings: app(CrawlerProxySettingsResolver::class))->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(crawlerMode: CrawlerProxyMode::Default),
    );
})->throws(NoProxyAvailableException::class);

test('default-режим без сохранённого узла создаёт typed direct-лизу', function (): void {
    $lease = makeResolver(crawlerSettings: app(CrawlerProxySettingsResolver::class))->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(crawlerMode: CrawlerProxyMode::Default),
    );

    expect($lease->isDirect)->toBeTrue()
        ->and($lease->source)->toBe(ProxyRouteSource::Direct);
});

test('weighted random с инъектированным источником выбирает предсказуемый узел', function (): void {
    config(['crawler.proxy.resolver.top_k' => 2]);

    $first = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 0]);
    $second = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 0]);

    $leaseLow = makeResolver(fn (): float => 0.0)->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
    $leaseHigh = makeResolver(fn (): float => 0.999999)->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    expect([$leaseLow->proxyId, $leaseHigh->proxyId])
        ->toEqual([$first->id, $second->id]);
});

test('reportOutcome с латентностью пишет latency_ms_avg узла (не NULL)', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    $target = ProxyTarget::factory()->create([
        'proxy_id' => $proxy->id,
        'target' => 'zakupki.gov.ru',
        'latency_ms_avg' => null,
    ]);

    $lease = makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
    makeResolver()->reportOutcome($lease, ProxyOutcome::success(latencyMs: 120));

    expect($target->refresh()->latency_ms_avg)->not->toBeNull();
});

test('reportOutcome на успехе сбрасывает целевой и глобальный breaker', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 3]);
    $target = ProxyTarget::factory()->create([
        'proxy_id' => $proxy->id,
        'target' => 'zakupki.gov.ru',
        'consecutive_failures' => 2,
    ]);

    $lease = makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
    makeResolver()->reportOutcome($lease, ProxyOutcome::success());

    expect($proxy->refresh()->fails_count)->toBe(0)
        ->and($target->refresh()->consecutive_failures)->toBe(0);
});

test('reportOutcome целевой причины не трогает глобальный fails_count', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create(['fails_count' => 0]);

    $lease = makeResolver()->resolve(scope: ProxyScope::CrawlDetail, target: 'a.example');
    makeResolver()->reportOutcome($lease, ProxyOutcome::failure(ProxyFailureReason::Http403));

    expect($proxy->refresh()->fails_count)->toBe(0);

    $target = ProxyTarget::query()->where('proxy_id', $proxy->id)->where('target', 'a.example')->firstOrFail();
    expect($target->consecutive_failures)->toBe(1);
});

test('reportOutcome глобальной причины не трогает целевой consecutive_failures', function (): void {
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create(['fails_count' => 0]);

    $lease = makeResolver()->resolve(scope: ProxyScope::CrawlDetail, target: 'a.example');
    makeResolver()->reportOutcome($lease, ProxyOutcome::failure(ProxyFailureReason::NetworkTimeout));

    expect($proxy->refresh()->fails_count)->toBe(1);

    $target = ProxyTarget::query()->where('proxy_id', $proxy->id)->where('target', 'a.example')->firstOrFail();
    expect($target->consecutive_failures)->toBe(0);
});

test('reportOutcome direct-лизы не создаёт health-строк', function (): void {
    makeResolver()->reportOutcome(
        ProxyLease::direct(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru'),
        ProxyOutcome::failure(ProxyFailureReason::NetworkTimeout),
    );

    expect(ProxyTarget::query()->count())->toBe(0);
});

test('политика required_country=RU не выдаёт узел другой страны ни при одном значении случайности', function (): void {
    Proxy::factory()->country('DE')->withScopes([ProxyScope::CrawlListing])->create();
    $allowed = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();

    foreach ([0.0, 0.5, 0.999999] as $randomValue) {
        $lease = makeResolver(fn (): float => $randomValue)->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

        expect($lease->proxyId)->toBe($allowed->id);
    }
});

test('узел без страны не проходит скоуп с непустым required_country', function (): void {
    Proxy::factory()->withScopes([ProxyScope::CrawlListing])->create();

    makeResolver()->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
})->throws(NoProxyAvailableException::class);

test('требование профиля, противоречащее required_country скоупа, даёт NoProxyAvailableException', function (): void {
    // Узел под требование профиля есть (NL), но политика скоупа держит только RU — пересечение
    // пусто: «профиль побеждает политику» не реализуется ни при каком условии (Implementation Rules P1.9).
    Proxy::factory()->country('NL')->withScopes([ProxyScope::CrawlListing])->create();

    makeResolver()->resolve(
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
        requirements: new ProxyRequirements(country: 'NL'),
    );
})->throws(NoProxyAvailableException::class);
