<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * P2.5 — команда `crawler:okpd2-sync`: пересборка карты «код ОКПД2 → id НСИ» и детект дрейфа.
 *
 * Ни один тест файла не ходит в сеть: `Http::fake()` обязателен, тела ответов берутся из
 * фикстуры `tests/Fixtures/Crawler/okpd2-children.json`. Фикстура СИНТЕТИЧЕСКАЯ —
 * реконструирована из `plans/archive/2026.08.06-CRAWL-HARDEN/artifacts/eis-okpd2-map.json` (живой замер
 * P1.2 от 2026-08-07): коды, id и наименования снятые, имена полей ответа — из
 * `findings/P1-okpd2-map.md` §1, но сырое тело ответа разведкой не сохранялось. Поддерево
 * усечено до подклассов, чтобы обход завершался за три запроса.
 */

/**
 * Площадка `eis` — синхронизатор ищет её по коду (карта живёт по площадкам).
 */
function okpd2SyncPlatform(): Platform
{
    return Platform::factory()->create(['code' => 'eis']);
}

/**
 * Активный узел скоупа `crawl_listing`: резолвер не выдаёт лизу без кандидата, а гео-политика
 * скоупа требует российский узел (`crawler.proxy.policy.crawl_listing.required_country`).
 */
function okpd2SyncProxy(): Proxy
{
    return Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
}

/**
 * `Http::fake()` по карте «parentId → тело ответа» из фикстуры: обход спрашивает детей поимённо,
 * поэтому одним статичным ответом его не покрыть.
 */
function fakeOkpd2Children(): void
{
    $fixture = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Crawler/okpd2-children.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    Http::fake(function (Request $request) use ($fixture) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $parentId = (string) ($query['parentId'] ?? '');

        return Http::response($fixture['responses'][$parentId] ?? []);
    });
}

test('обход собирает узлы поддерева и пишет их в карту с synced_at', function () {
    Sleep::fake();
    okpd2SyncPlatform();
    okpd2SyncProxy();
    fakeOkpd2Children();

    $this->artisan('crawler:okpd2-sync')->assertExitCode(0);

    // 3 запроса (корень + два класса) дают 6 узлов: 41, 43, 41.1, 41.2, 43.1, 43.2.
    Http::assertSentCount(3);

    expect(Okpd2Node::query()->count())->toBe(6)
        ->and(Okpd2Node::query()->whereNull('synced_at')->count())->toBe(0);

    $node = Okpd2Node::query()->where('code', '41.1')->first();

    // Корень поддерева (раздел `F`) в карту не пишется — классы получают `parent_code = null`
    // и уровень 2, как в датафайле P1.2.
    expect($node->external_id)->toBe('8874099')
        ->and($node->parent_code)->toBe('41')
        ->and($node->level)->toBe(3)
        ->and(Okpd2Node::query()->where('code', '41')->first()->parent_code)->toBeNull()
        ->and(Okpd2Node::query()->where('code', '41')->first()->level)->toBe(2);
});

test('сменившийся external_id кода активного профиля даёт ненулевой код возврата', function () {
    Sleep::fake();
    $platform = okpd2SyncPlatform();
    okpd2SyncProxy();
    fakeOkpd2Children();

    Okpd2Node::factory()->create([
        'platform_id' => $platform->id,
        'code' => '43',
        'external_id' => '7000000',
        'level' => 2,
    ]);

    $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'fz44']);
    Crawler::factory()->for($registry)->create(['is_active' => true, 'filter' => ['okpd2Codes' => ['43']]]);

    $this->artisan('crawler:okpd2-sync')
        ->expectsOutputToContain('сменился id узла 43: 7000000 → 8873921')
        ->assertExitCode(1);

    expect(Okpd2Node::query()->where('code', '43')->first()->external_id)->toBe('8873921');
});

test('дрейф кода, которым не фильтрует ни один активный профиль, кода возврата не меняет', function () {
    Sleep::fake();
    $platform = okpd2SyncPlatform();
    okpd2SyncProxy();
    fakeOkpd2Children();

    Okpd2Node::factory()->create([
        'platform_id' => $platform->id,
        'code' => '43',
        'external_id' => '7000000',
        'level' => 2,
    ]);

    $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'fz44']);
    Crawler::factory()->for($registry)->create(['is_active' => false, 'filter' => ['okpd2Codes' => ['43']]]);

    $this->artisan('crawler:okpd2-sync')->assertExitCode(0);
});

test('--dry-run отчитывается, но в таблицу не пишет', function () {
    Sleep::fake();
    $platform = okpd2SyncPlatform();
    okpd2SyncProxy();
    fakeOkpd2Children();

    Okpd2Node::factory()->create([
        'platform_id' => $platform->id,
        'code' => '43',
        'external_id' => '7000000',
        'level' => 2,
    ]);

    $this->artisan('crawler:okpd2-sync', ['--dry-run' => true])
        ->expectsOutputToContain('dry-run (карта не тронута)')
        ->assertExitCode(0);

    expect(Okpd2Node::query()->count())->toBe(1)
        ->and(Okpd2Node::query()->where('code', '43')->first()->external_id)->toBe('7000000')
        ->and(Okpd2Node::query()->whereNotNull('synced_at')->count())->toBe(0);
});

test('потолок запросов останавливает обход, а остаток виден в отчёте', function () {
    Sleep::fake();
    okpd2SyncPlatform();
    okpd2SyncProxy();
    fakeOkpd2Children();

    $this->artisan('crawler:okpd2-sync', ['--max-requests' => 1])
        ->expectsOutputToContain('Не обойдено (потолок запросов): 2 узлов')
        ->assertExitCode(0);

    Http::assertSentCount(1);

    expect(Okpd2Node::query()->count())->toBe(2);
});

test('узел, не пришедший с площадки, из таблицы не удаляется, а называется в отчёте', function () {
    Sleep::fake();
    $platform = okpd2SyncPlatform();
    okpd2SyncProxy();
    fakeOkpd2Children();

    Okpd2Node::factory()->create([
        'platform_id' => $platform->id,
        'code' => '41.9',
        'external_id' => '8874199',
        'parent_code' => '41',
        'level' => 3,
    ]);

    $this->artisan('crawler:okpd2-sync')
        ->expectsOutputToContain('не пришёл узел 41.9')
        ->assertExitCode(0);

    expect(Okpd2Node::query()->where('code', '41.9')->exists())->toBeTrue()
        ->and(Okpd2Node::query()->where('code', '41.9')->first()->synced_at)->toBeNull();
});
