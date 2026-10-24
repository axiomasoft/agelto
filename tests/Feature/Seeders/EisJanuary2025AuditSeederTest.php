<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\Run;
use Database\Seeders\Platforms\Eis\EisCrawlerProxySeeder;
use Database\Seeders\Platforms\Eis\EisJanuary2025AuditSeeder;
use Database\Seeders\Platforms\Eis\EisPlatformSeeder;
use Database\Seeders\Procurement\CrawlerProfileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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

test('сид прокси декодирует реквизиты и разрешает только CSV листинг', function (): void {
    $this->seed(EisCrawlerProxySeeder::class);

    $proxy = Proxy::query()->sole();

    expect($proxy->host)->toBe('proxy.invalid')
        ->and($proxy->port)->toBe(8080)
        ->and($proxy->username)->toBe('audit@user')
        ->and($proxy->password)->toBe('p:ss+word')
        ->and($proxy->country)->toBe('RU')
        ->and($proxy->is_active)->toBeTrue()
        ->and($proxy->scopes->all())->toBe([ProxyScope::CrawlListing]);
});

test('повторный сид обновляет пароль существующего прокси без дубля', function (): void {
    $this->seed(EisCrawlerProxySeeder::class);
    $proxyId = Proxy::query()->sole()->id;

    setEisAuditProxyEnvironment('http://audit%40user:rotated%3Apassword@proxy.invalid:8080');
    $this->seed(EisCrawlerProxySeeder::class);

    $proxy = Proxy::query()->sole();
    expect($proxy->id)->toBe($proxyId)
        ->and($proxy->password)->toBe('rotated:password')
        ->and($proxy->scopes->all())->toBe([ProxyScope::CrawlListing]);
});

test('сид аудита создаёт отдельный январский CSV профиль с привязанным прокси', function (): void {
    $this->seed([EisPlatformSeeder::class, CrawlerProfileSeeder::class]);
    $annual = Crawler::query()->where('mode', CrawlerMode::Archive)->sole();
    $annualBefore = $annual->getRawOriginal();

    $this->seed(EisJanuary2025AuditSeeder::class);

    $audit = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
    $proxy = Proxy::query()->sole();

    expect($audit->id)->not->toBe($annual->id)
        ->and($audit->registry_id)->toBe($annual->registry_id)
        ->and($audit->mode)->toBe(CrawlerMode::Archive)
        ->and($audit->execution_lane)->toBe(CrawlerExecutionLane::Long)
        ->and($audit->source_method)->toBe(SourceMethod::Csv)
        ->and($audit->fallback_method)->toBeNull()
        ->and($audit->schedule)->toBeNull()
        ->and($audit->is_active)->toBeTrue()
        ->and($audit->proxy_mode)->toBe(CrawlerProxyMode::Profile)
        ->and($audit->proxy_country)->toBe('RU')
        ->and($audit->proxies()->pluck('prc_proxies.id')->all())->toBe([$proxy->id])
        ->and($audit->filter->publishedFrom->toDateString())->toBe('2025-01-01')
        ->and($audit->filter->publishedTo->toDateString())->toBe('2025-01-31')
        ->and($audit->filter->sortField)->toBe(ListingSortField::Published)
        ->and($audit->filter->sortAscending)->toBeTrue()
        ->and($audit->filter->stageCompleted)->toBeTrue()
        ->and($audit->filter->okpd2Codes)->toBe(['43'])
        ->and($audit->filter->priceTo)->toBe(1_000_000_000_000)
        ->and($audit->getRawOriginal('options'))->toBe($annualBefore['options'])
        ->and($annual->refresh()->getRawOriginal())->toBe($annualBefore)
        ->and(Crawler::query()->count())->toBe(3)
        ->and(Crawler::query()->where('mode', CrawlerMode::Fresh)->sole()->is_active)->toBeFalse();
});

test('повторный январский сид сохраняет идентификаторы и единственную привязку', function (): void {
    $this->seed([EisPlatformSeeder::class, CrawlerProfileSeeder::class, EisJanuary2025AuditSeeder::class]);
    $crawlerId = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole()->id;
    $proxyId = Proxy::query()->sole()->id;

    $this->seed(EisJanuary2025AuditSeeder::class);

    $audit = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
    expect($audit->id)->toBe($crawlerId)
        ->and(Proxy::query()->sole()->id)->toBe($proxyId)
        ->and($audit->proxies()->pluck('prc_proxies.id')->all())->toBe([$proxyId])
        ->and(DB::table('prc_crawler_proxy')->where('crawler_id', $crawlerId)->count())->toBe(1)
        ->and(Crawler::query()->count())->toBe(3);
});

test('активный прогон запрещает перезапись январского профиля', function (): void {
    $this->seed([EisPlatformSeeder::class, CrawlerProfileSeeder::class, EisJanuary2025AuditSeeder::class]);
    $audit = Crawler::query()->where('name', EisJanuary2025AuditSeeder::NAME)->sole();
    $filter = clone $audit->filter;
    $filter->priceTo = 12_000_000;
    $audit->update(['filter' => $filter]);
    $before = $audit->refresh()->getRawOriginal();
    $proxyIds = $audit->proxies()->pluck('prc_proxies.id')->all();
    $proxy = Proxy::query()->sole();
    $proxyBefore = $proxy->getRawOriginal();
    $run = Run::factory()->create(['crawler_id' => $audit->id, 'status' => RunStatus::Running]);
    setEisAuditProxyEnvironment('http://audit%40user:must-not-rotate@proxy.invalid:8080');

    expect(fn () => $this->seed(EisJanuary2025AuditSeeder::class))
        ->toThrow(RuntimeException::class, 'Pause the audit run');

    expect($audit->refresh()->getRawOriginal())->toBe($before)
        ->and($audit->proxies()->pluck('prc_proxies.id')->all())->toBe($proxyIds)
        ->and($proxy->refresh()->getRawOriginal())->toBe($proxyBefore)
        ->and($run->refresh()->status)->toBe(RunStatus::Running);
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
