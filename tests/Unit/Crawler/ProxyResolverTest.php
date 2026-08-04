<?php

declare(strict_types=1);

use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\NoProxyAvailableException;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyTarget;
use App\Repositories\Procurement\ProxyHealthStoreRepository;
use App\Services\Procurement\Proxy\ProxyCircuitBreaker;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\ProxyScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeResolver(?Closure $randomSource = null): ProxyResolver
{
    return new ProxyResolver(
        scoreCalculator: new ProxyScoreCalculator,
        breaker: new ProxyCircuitBreaker,
        health: new ProxyHealthStoreRepository,
        randomSource: $randomSource ?? fn (): float => 0.0,
    );
}

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

test('weighted random с инъектированным источником выбирает предсказуемый узел', function (): void {
    config(['crawler.proxy.resolver.top_k' => 2]);

    $first = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 0]);
    $second = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create(['fails_count' => 0]);

    $leaseLow = makeResolver(fn (): float => 0.0)->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');
    $leaseHigh = makeResolver(fn (): float => 0.999999)->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    expect([$leaseLow->proxyId, $leaseHigh->proxyId])
        ->toEqual([$first->id, $second->id]);
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
