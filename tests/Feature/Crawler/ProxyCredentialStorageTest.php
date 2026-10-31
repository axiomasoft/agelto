<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

beforeEach(fn () => LegacyBusinessNetwork::install(fakeResponses: false));
afterEach(fn () => putenv('P26_PROXY_TEST_SECRET'));

function secretReferenceProxy(): Proxy
{
    $proxy = Proxy::factory()->create(['username' => 'user']);
    DB::table('prc_network_routes')->where('id', $proxy->route_id)->update(['secret_ref' => 'P26_PROXY_TEST_SECRET']);
    putenv('P26_PROXY_TEST_SECRET=p@ss:word');

    return $proxy;
}

test('пароль прокси хранится в БД зашифрованным, а модель читает открытое значение', function (): void {
    // P2.6 replacement is stricter: only a secret reference is persisted, never its value.
    $proxy = secretReferenceProxy();
    expect(Schema::hasColumn('prc_proxies', 'password'))->toBeFalse()
        ->and(DB::table('prc_network_routes')->where('id', $proxy->route_id)->value('secret_ref'))->toBe('P26_PROXY_TEST_SECRET')
        ->and(json_encode((array) DB::table('prc_proxies')->find($proxy->id)))->not->toContain('p@ss:word');
});

test('пароль прокси не сериализуется моделью', function (): void {
    $proxy = secretReferenceProxy();
    expect($proxy->fresh()->toArray())->not->toHaveKey('password')
        ->and($proxy->fresh()->load('route')->toJson())->not->toContain('p@ss:word');
});

test('резолвер выдаёт лизу с расшифрованными учётными данными узла', function (): void {
    $proxy = secretReferenceProxy();
    $crawler = EisCrawlerFactory::new()->withProxies($proxy)->create();
    $policy = app(ProxyBindingResolver::class)->forCrawler($crawler);
    $binding = array_values($policy->bindings)[0];
    expect($binding->credentials())->toBe('user:p@ss:word')
        ->and($binding->proxy())->toBe('http://'.$proxy->host.':'.$proxy->port)
        ->and(fn () => serialize($binding))->toThrow(LogicException::class);
    putenv('P26_PROXY_TEST_SECRET');
    expect(fn () => app(RouteRepository::class)->bindings(new ProcessUuid($crawler->route_profile_id)))
        ->toThrow(OutboundUnavailable::class, 'configuration_hold');
});
