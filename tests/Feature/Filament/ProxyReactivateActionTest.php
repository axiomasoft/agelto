<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\ListProxies;
use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

test('оператор видит действие возврата отключённого прокси', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
    $proxy = Proxy::factory()->create(['is_active' => false, 'disabled_at' => now()]);

    Livewire::test(ListProxies::class)
        ->assertTableActionVisible('reactivate', record: $proxy)
        ->callTableAction('reactivate', record: $proxy)
        ->assertHasNoTableActionErrors();

    expect($proxy->refresh()->is_active)->toBeTrue();
});

test('пользователь без proxy.update не может открыть витрину прокси', function (): void {
    actingAs(User::factory()->create());

    get(ProxyResource::getUrl('index'))->assertForbidden();
});
