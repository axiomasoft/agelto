<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\ListProxies;
use App\Filament\Resources\Procurement\Crawler\Proxies\ProxyResource;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);
    LegacyBusinessNetwork::install(fakeResponses: false);
});

test('оператор видит действие возврата отключённого прокси', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
    $proxy = Proxy::factory()->create();
    DB::table('prc_network_routes')->where('id', $proxy->route_id)->update(['enabled' => false, 'disabled_at' => now(), 'disabled_reason' => 'fixture']);

    Livewire::test(ListProxies::class)
        ->assertTableActionVisible('reactivate', record: $proxy)
        ->callTableAction('reactivate', record: $proxy, data: ['reason' => 'Проверены учётные данные'])
        ->assertHasNoTableActionErrors();

    expect($proxy->refresh()->route->enabled)->toBeTrue()
        ->and(DB::table('prc_process_transitions')->where('actor_id', $operator->id)->count())->toBe(1);
});

test('пользователь без proxy.update не может открыть витрину прокси', function (): void {
    actingAs(User::factory()->create());

    get(ProxyResource::getUrl('index'))->assertForbidden();
});
