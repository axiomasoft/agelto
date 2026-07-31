<?php

declare(strict_types=1);

use App\Enums\Procurement\ProxyFailureReason;
use App\Enums\Procurement\ProxyScope;
use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\CreateProxy;
use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\EditProxy;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

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
            'password' => 'secret',
            'country' => 'RU',
            'scopes' => [ProxyScope::CrawlListing->value],
            'is_active' => true,
            'fails_count' => 0,
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

    $proxy = Proxy::factory()->create(['password' => 'original-secret']);

    Livewire::test(EditProxy::class, ['record' => $proxy->getKey()])
        ->fillForm(['password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($proxy->refresh()->password)->toBe('original-secret');
});

test('поле здоровья не редактируется через форму', function (): void {
    actingAs(proxyResourceOperator());

    $proxy = Proxy::factory()->create([
        'open_count' => 2,
        'last_failure_reason' => ProxyFailureReason::NetworkTimeout->value,
    ]);

    Livewire::test(EditProxy::class, ['record' => $proxy->getKey()])
        ->fillForm(['open_count' => 99, 'last_failure_reason' => ProxyFailureReason::Http403->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($proxy->refresh())
        ->open_count->toBe(2)
        ->last_failure_reason->toBe(ProxyFailureReason::NetworkTimeout->value);
});
