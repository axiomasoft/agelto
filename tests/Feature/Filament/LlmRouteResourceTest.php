<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\LlmRoutes\LlmRouteResource;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;

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
    get(LlmRouteResource::getUrl('create'))
        ->assertOk()
        ->assertSee('Назначение модели')
        ->assertSee('Скрининг документа');
});
