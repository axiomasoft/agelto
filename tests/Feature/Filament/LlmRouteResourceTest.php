<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\ListAiRoutes;
use App\Filament\Resources\Procurement\Ai\Providers\Pages\ListProviders;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(function (): void {
    artisan('guard:sync-roles')->assertExitCode(0);

    $user = User::factory()->create();
    $user->assignRole(SuperAdminRole::class);

    actingAs($user);
});

test('страница создания модели для задачи отображает назначения без runtime exception', function (): void {
    $provider = Provider::factory()->create(['code' => 'primary-gemini']);

    get(LlmRouteResource::getUrl('create'))
        ->assertOk()
        ->assertSee('Назначение модели')
        ->assertSee('Скрининг документа')
        ->assertSee($provider->code);
});

test('маршрут показывает код провайдера и модель вместо технического идентификатора', function (): void {
    $provider = Provider::factory()->create([
        'code' => 'primary-gemini',
        'driver' => 'gemini',
    ]);
    $route = LlmRoute::factory()->for($provider)->create([
        'model' => 'gemini-2.5-flash',
    ]);

    Livewire::test(ListAiRoutes::class)
        ->assertCanSeeTableRecords([$route])
        ->assertTableColumnStateSet('provider.code', 'primary-gemini', record: $route)
        ->assertTableColumnStateSet('model', 'gemini-2.5-flash', record: $route);
});

test('список провайдеров показывает код, драйвер и адрес подключения', function (): void {
    $provider = Provider::factory()->create([
        'code' => 'reserve-gemini',
        'driver' => 'gemini',
        'base_url' => 'https://generativelanguage.googleapis.com',
    ]);

    Livewire::test(ListProviders::class)
        ->assertCanSeeTableRecords([$provider])
        ->assertTableColumnStateSet('code', 'reserve-gemini', record: $provider)
        ->assertTableColumnStateSet('driver', 'gemini', record: $provider)
        ->assertTableColumnStateSet('base_url', 'https://generativelanguage.googleapis.com', record: $provider);
});
