<?php

declare(strict_types=1);

use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

beforeEach(fn () => LegacyBusinessNetwork::install(fakeResponses: false));

test('резолвер профиля возвращает только явно привязанные узлы', function (): void {
    $bound = Proxy::factory()->create();
    Proxy::factory()->create();
    $crawler = Crawler::factory()->withProxies($bound)->create();
    $policy = app(ProxyBindingResolver::class)->forCrawler($crawler);
    expect(array_keys($policy->bindings))->toBe([$bound->route_id])->and($policy->profileId->value)->toBe($crawler->route_profile_id);
});

test('резолвер стадии объединяет только наборы активных proxy-профилей реестра закупки', function (): void {
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->for($registry)->create();
    $first = Proxy::factory()->create();
    $second = Proxy::factory()->create();
    $crawler = Crawler::factory()->for($registry)->withProxies($first, $second)->create();
    Crawler::factory()->for($registry)->create(['route_profile_id' => $crawler->route_profile_id]);
    Crawler::factory()->for($registry)->withProxies(Proxy::factory()->create())->create(['is_active' => false]);
    $otherRegistry = Registry::factory()->create();
    Crawler::withoutEvents(fn () => Crawler::factory()->for($otherRegistry)->withProxies(Proxy::factory()->create())->create());
    $policy = app(ProxyBindingResolver::class)->forProcurement($procurement->id);
    // Exact shared canonical profile replaces implicit unions, so revocation remains fenced.
    expect(array_keys($policy->bindings))->toEqualCanonicalizing([$first->route_id, $second->route_id])
        ->and($policy->profileId->value)->toBe($crawler->route_profile_id);
});

test('резолвер стадии выбирает default без profile и direct только при явном opt-out всех профилей', function (): void {
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->for($registry)->create();
    $crawler = Crawler::factory()->for($registry)->create();
    $default = app(ProxyBindingResolver::class)->forProcurement($procurement->id);
    $profile = DB::table('prc_network_route_profiles')->where('profile_key', 'default')->sole();
    expect($default->profileId->value)->toBe($profile->id)
        ->and(array_keys($default->bindings))->toBe([DB::table('prc_network_routes')->where('kind', 'direct')->sole()->id]);
    $explicit = Crawler::factory()->withoutProxy()->create();
    $crawler->update(['route_profile_id' => $explicit->route_profile_id]);
    $direct = app(ProxyBindingResolver::class)->forProcurement($procurement->id);
    expect($direct->profileId->value)->toBe($explicit->route_profile_id)
        ->and(array_values($direct->bindings)[0]->proxy())->toBe('');
});

test('разные активные профили закупки дают явный configuration hold без расширения маршрутов', function (): void {
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->for($registry)->create();
    Crawler::factory()->for($registry)->withProxies(Proxy::factory()->create())->create();
    Crawler::factory()->for($registry)->withProxies(Proxy::factory()->create())->create();
    expect(fn () => app(ProxyBindingResolver::class)->forProcurement($procurement->id))
        ->toThrow(OutboundUnavailable::class, 'configuration_hold');
});
