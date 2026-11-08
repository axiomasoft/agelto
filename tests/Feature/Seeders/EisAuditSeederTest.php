<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Network\RouteRepository;
use Database\Seeders\Platforms\Eis\EisAuditSeeder;
use Database\Seeders\Platforms\Eis\EisCrawlerProfileSeeder;
use Database\Seeders\Platforms\Eis\EisCrawlerProxySeeder;
use Database\Seeders\Platforms\Eis\EisPlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

function setEisAuditProxyEnvironment(string $value): void
{
    $_ENV['PLAYWRIGHT_MCP_PROXY_SERVER'] = $value;
    $_SERVER['PLAYWRIGHT_MCP_PROXY_SERVER'] = $value;
    putenv('PLAYWRIGHT_MCP_PROXY_SERVER='.$value);
}

beforeEach(function (): void {
    $key = 'PLAYWRIGHT_MCP_PROXY_SERVER';
    $this->auditProxyEnvironment = [
        'env' => $_ENV[$key] ?? null,
        'server' => $_SERVER[$key] ?? null,
        'getenv' => getenv($key),
    ];
    setEisAuditProxyEnvironment('http://audit%40user:p%3Ass%2Bword@proxy.invalid:8080');
    Queue::fake();
});

afterEach(function (): void {
    $key = 'PLAYWRIGHT_MCP_PROXY_SERVER';
    foreach (['env', 'server'] as $source) {
        $value = $this->auditProxyEnvironment[$source];
        if ($source === 'env') {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        } elseif ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }
    $original = $this->auditProxyEnvironment['getenv'];
    putenv($original === false ? $key : $key.'='.$original);
    Queue::assertNothingPushed();
});

test('сид прокси декодирует реквизиты и привязывает маршрут для всех стадий ЕИС', function (): void {
    $this->seed(EisCrawlerProxySeeder::class);

    $proxy = Proxy::query()->sole();
    $routes = app(RouteRepository::class);

    expect($proxy->host)->toBe('proxy.invalid')
        ->and($proxy->port)->toBe(8080)
        ->and($proxy->username)->toBe('audit@user')
        ->and($routes->bindings($routes->profile(null, 'eis.audit'))[0]->credentials())->toBe('audit@user:p:ss+word')
        ->and($routes->bindings($routes->profile(null, 'default'))[0]->id->value)->toBe($proxy->route_id)
        ->and($proxy->fresh()->load('route')->toJson())->not->toContain('p:ss+word')
        ->and($proxy->country)->toBe('RU')
        ->and($proxy->route->enabled)->toBeTrue()
        ->and($proxy->scopes->all())->toBe([ProxyScope::CrawlListing, ProxyScope::CrawlDetail, ProxyScope::DocumentDownload]);
});

test('повторный сид обновляет пароль существующего прокси без дубля', function (): void {
    $this->seed(EisCrawlerProxySeeder::class);
    $proxyId = Proxy::query()->sole()->id;
    $version = Proxy::query()->sole()->route->credential_version;

    setEisAuditProxyEnvironment('http://audit%40user:rotated%3Apassword@proxy.invalid:8080');
    $this->seed(EisCrawlerProxySeeder::class);

    $proxy = Proxy::query()->sole();
    expect($proxy->id)->toBe($proxyId)
        ->and($proxy->route->credential_version)->toBe($version + 1)
        ->and(app(RouteRepository::class)->bindings(app(RouteRepository::class)->profile(null, 'eis.audit'))[0]->credentials())->toBe('audit@user:rotated:password')
        ->and($proxy->scopes->all())->toBe([ProxyScope::CrawlListing, ProxyScope::CrawlDetail, ProxyScope::DocumentDownload]);
});

test('сид аудита создаёт отдельный профиль обхода с привязанным прокси', function (): void {
    $this->seed([EisPlatformSeeder::class, EisCrawlerProfileSeeder::class]);
    $annual = Crawler::query()->where('name', 'ЕИС 44-ФЗ — архив 2025 (завершённые)')->sole();
    $annualBefore = $annual->getRawOriginal();

    $this->seed(EisAuditSeeder::class);

    $audit = Crawler::query()->where('name', EisAuditSeeder::NAME)->sole();
    $proxy = Proxy::query()->sole();

    expect($audit->id)->not->toBe($annual->id)
        ->and($audit->registry_id)->toBe($annual->registry_id)
        ->and($audit->listing_kind)->toBe(AcquisitionKind::Archive)
        ->and($audit->getAttributes())->not->toHaveKeys(['execution_lane', 'source_method', 'fallback_method'])
        ->and($audit->schedule)->toBeNull()
        ->and($audit->is_active)->toBeTrue()
        ->and($audit->route_profile_id)->not->toBeNull()
        ->and(array_values(app(RouteRepository::class)->bindings(new ProcessUuid($audit->route_profile_id)))[0]->id->value)->toBe($proxy->route_id)
        ->and($audit->filter->publishedFrom->toDateString())->toBe('2025-01-01')
        ->and($audit->filter->publishedTo->toDateString())->toBe('2025-12-31')
        ->and($audit->filter->sortField)->toBe(ListingSortField::Published)
        ->and($audit->filter->sortAscending)->toBeTrue()
        ->and($audit->filter->stageCompleted)->toBeTrue()
        ->and($audit->filter->okpd2Codes)->toBe(['43'])
        ->and($audit->filter->priceTo)->toBe(1_000_000_000_000)
        ->and($annual->refresh()->getRawOriginal())->toBe($annualBefore)
        ->and(Crawler::query()->count())->toBe(4)
        ->and(Crawler::query()->where('listing_kind', AcquisitionKind::Fresh)->sole()->is_active)->toBeFalse();
});

test('повторный сид аудита сохраняет идентификаторы и единственную привязку', function (): void {
    $this->seed([EisPlatformSeeder::class, EisCrawlerProfileSeeder::class, EisAuditSeeder::class]);
    $crawlerId = Crawler::query()->where('name', EisAuditSeeder::NAME)->sole()->id;
    $proxyId = Proxy::query()->sole()->id;

    $this->seed(EisAuditSeeder::class);

    $audit = Crawler::query()->where('name', EisAuditSeeder::NAME)->sole();
    expect($audit->id)->toBe($crawlerId)
        ->and(Proxy::query()->sole()->id)->toBe($proxyId)
        ->and(app(RouteRepository::class)->bindings(new ProcessUuid($audit->route_profile_id)))->toHaveCount(1)
        ->and(DB::table('prc_network_route_profile_members')->where('profile_id', $audit->route_profile_id)->count())->toBe(1)
        ->and(Crawler::query()->count())->toBe(4);
});

test('активный прогон запрещает перезапись профиля аудита', function (): void {
    $this->seed([EisPlatformSeeder::class, EisCrawlerProfileSeeder::class, EisAuditSeeder::class]);
    $audit = Crawler::query()->where('name', EisAuditSeeder::NAME)->sole();
    $filter = clone $audit->filter;
    $filter->priceTo = 12_000_000;
    $audit->update(['filter' => $filter]);
    $before = $audit->refresh()->getRawOriginal();
    $profileId = $audit->route_profile_id;
    $proxy = Proxy::query()->sole();
    $proxyBefore = $proxy->getRawOriginal();
    FinalizeFixtures::authority([StageCode::Listing]);
    $subject = LedgerFixtures::crawlBlock();
    DB::table('prc_process_runs')->where('id', $subject['run']->value)->update(['profile_id' => $audit->id]);
    $work = LedgerFixtures::storeWork(LedgerFixtures::listingInput($subject['block'], $subject['filter_hash'], $subject['access_policy_hash']), originRunId: $subject['run']);
    FinalizeFixtures::running($work);
    setEisAuditProxyEnvironment('http://audit%40user:must-not-rotate@proxy.invalid:8080');

    expect(fn () => $this->seed(EisAuditSeeder::class))
        ->toThrow(RuntimeException::class, 'Pause the audit run');

    expect($audit->refresh()->getRawOriginal())->toBe($before)
        ->and($audit->route_profile_id)->toBe($profileId)
        ->and($proxy->refresh()->getRawOriginal())->toBe($proxyBefore)
        ->and(ExecutionFixtures::work($work)->state)->toBe('running');
});

test('сид прокси отвергает неподдерживаемую схему до записи в БД', function (string $url): void {
    setEisAuditProxyEnvironment($url);

    expect(fn () => $this->seed(EisCrawlerProxySeeder::class))
        ->toThrow(RuntimeException::class, 'valid HTTP proxy URL');

    expect(Proxy::query()->count())->toBe(0);
})->with([
    'SOCKS5' => 'socks5://user:password@proxy.invalid:8080',
    'HTTPS' => 'https://user:password@proxy.invalid:8080',
]);
