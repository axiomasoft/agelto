<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\CreateProxy;
use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\EditProxy;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(function (): void {
    artisan('guard:sync-roles');
    LegacyBusinessNetwork::install(fakeResponses: false);
});

function proxyResourceOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

test('сохранение прокси со скоупом и страной кладёт типизированные значения', function (): void {
    actingAs(proxyResourceOperator());

    Livewire::test(CreateProxy::class)
        ->fillForm([
            'service' => 'webshare',
            'host' => 'proxy.example',
            'port' => 8080,
            'username' => 'user',
            'secret_ref' => 'PROXY_TEST_SECRET',
            'country' => 'RU',
            'scopes' => [ProxyScope::CrawlListing->value],
            'enabled' => true,
            'reason' => 'Настройка маршрута',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $proxy = Proxy::query()->sole();

    expect($proxy->country)->toBe('RU')
        ->and($proxy->scopes)->toHaveCount(1)
        ->and($proxy->scopes->first())->toBe(ProxyScope::CrawlListing);
});

test('пустой пароль при правке не перетирает сохранённый', function (): void {
    actingAs(proxyResourceOperator());

    $proxy = Proxy::factory()->create();
    DB::table('prc_network_routes')->where('id', $proxy->route_id)->update(['secret_ref' => 'ORIGINAL_SECRET']);

    Livewire::test(EditProxy::class, ['record' => $proxy->getKey()])
        ->fillForm(['reason' => 'Правка имени', 'service' => 'updated'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($proxy->refresh()->route->secret_ref)->toBe('ORIGINAL_SECRET');
});

test('поле здоровья не редактируется через форму', function (): void {
    actingAs(proxyResourceOperator());

    $proxy = Proxy::factory()->create();
    $route = $proxy->route;
    $before = $route->toArray();
    Livewire::test(EditProxy::class, ['record' => $proxy->getKey()])
        ->fillForm(['reason' => 'Правка конфигурации', 'open_count' => 99, 'last_failure_reason' => 'http_403'])
        ->call('save')->assertHasNoFormErrors();
    expect(Schema::hasColumn('prc_proxies', 'open_count'))->toBeFalse()
        ->and(DB::table('prc_route_health')->where('route_id', $route->id)->value('state'))->toBe('unknown')
        ->and($route->refresh()->enabled)->toBe($before['enabled']);
});
