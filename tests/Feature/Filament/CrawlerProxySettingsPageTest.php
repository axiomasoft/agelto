<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Filament\Pages\CrawlerProxySettingsPage;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use App\Settings\Procurement\CrawlerProxySettings;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

function crawlerProxySettingsOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

/** @return list<ProxyScope> */
function crawlerProxyScopes(): array
{
    return [
        ProxyScope::CrawlListing,
        ProxyScope::CrawlDetail,
        ProxyScope::DocumentDownload,
    ];
}

test('оператор сохраняет nullable default только через action и видит безопасную подпись', function (): void {
    actingAs(crawlerProxySettingsOperator());

    $proxy = Proxy::factory()
        ->withScopes(scopes: crawlerProxyScopes())
        ->create([
            'service' => 'safe-provider',
            'host' => 'proxy.example',
            'port' => 8080,
            'username' => 'private-user',
            'password' => 'private-password',
            'country' => 'RU',
        ]);

    Livewire::test(CrawlerProxySettingsPage::class)
        ->assertFormComponentExists('default_proxy_id', function (Select $component) use ($proxy): bool {
            expect($component->getOptions())
                ->toHaveKey($proxy->getKey(), 'safe-provider — proxy.example:8080 (RU)')
                ->not->toContain('private-user')
                ->not->toContain('private-password');

            return true;
        })
        ->fillForm(['default_proxy_id' => $proxy->getKey()])
        ->call('save')
        ->assertNotified('Настройки прокси сохранены')
        ->assertDontSee('private-user')
        ->assertDontSee('private-password');

    expect((new CrawlerProxySettings)->defaultProxyId)->toBe($proxy->getKey());
});

test('страница исключает неактивные и неполные default-прокси, а action повторно отвергает их', function (): void {
    actingAs(crawlerProxySettingsOperator());

    $missingScope = Proxy::factory()->withScopes(scopes: [ProxyScope::CrawlListing])->create();
    $inactive = Proxy::factory()->withScopes(scopes: crawlerProxyScopes())->create(['is_active' => false]);

    Livewire::test(CrawlerProxySettingsPage::class)
        ->assertFormComponentExists('default_proxy_id', function (Select $component) use ($missingScope, $inactive): bool {
            expect($component->getOptions())
                ->not->toHaveKey($missingScope->getKey())
                ->not->toHaveKey($inactive->getKey());

            return true;
        })
        ->set('data.default_proxy_id', $missingScope->getKey())
        ->call('save')
        ->assertNotified();

    expect((new CrawlerProxySettings)->defaultProxyId)->toBeNull();
});

test('страница скрыта и закрыта прямым URL без ProxyPermission Update', function (): void {
    actingAs(User::factory()->create());

    expect(CrawlerProxySettingsPage::canAccess())->toBeFalse();

    get(CrawlerProxySettingsPage::getUrl())->assertForbidden();
});
