<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Crawler\Runs\RunResource;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Guards\Admin\Permissions\DocumentationPermission;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Prompt;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

test('суперадмин проходит на admin-панель, пользователь без ролей — нет', function () {
    $panel = Filament::getPanel('admin');

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);
    $guest = User::factory()->create();

    expect($superAdmin->canAccessPanel($panel))->toBeTrue()
        ->and($guest->canAccessPanel($panel))->toBeFalse();
});

test('оператор имеет CRUD на конфигурации, управляет прогонами, но не трогает реестр и роли', function () {
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    expect($operator->can('admin.platform.create'))->toBeTrue()
        ->and($operator->can('admin.ai_route.update'))->toBeTrue()
        ->and($operator->can('admin.run.update'))->toBeTrue()
        // прогоны не создаёт/не удаляет, реестр закупок и роли — вне доступа
        ->and($operator->can('admin.run.create'))->toBeFalse()
        ->and($operator->can('admin.procurement.create'))->toBeFalse()
        ->and($operator->can('admin.role.create'))->toBeFalse();
});

test('аналитик видит продуктовые реестры только на чтение', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);

    expect($analyst->can('admin.procurement.view_any'))->toBeTrue()
        ->and($analyst->can('admin.run.view_any'))->toBeTrue()
        // ни мутаций реестра, ни операторской конфигурации
        ->and($analyst->can('admin.procurement.delete'))->toBeFalse()
        ->and($analyst->can('admin.run.update'))->toBeFalse()
        ->and($analyst->can('admin.platform.view_any'))->toBeFalse();
});

test('суперадмин проходит любой gate через wildcard', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    expect($superAdmin->can('admin.procurement.delete'))->toBeTrue()
        ->and($superAdmin->can('admin.role.delete'))->toBeTrue()
        ->and($superAdmin->hasPermission(permission: DocumentationPermission::View, panelId: 'admin'))->toBeTrue();
});

test('view-only ресурсы Run и Procurement не имеют create/edit страниц', function () {
    expect(array_keys(RunResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(ProcurementResource::getPages()))->toBe(['index', 'view']);
});

test('PromptResource вне enum-RBAC каталога, остальные ресурсы и коарс-право на промты не задеты', function () {
    expect(config('az-guard-filament.exclude.resources'))->toContain(PromptResource::class);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    // Коарс-право на Prompt по-прежнему каталогизировано и грантуется —
    // exclude снимает только per-record enum-RBAC короткое замыкание,
    // не убирает PromptPermission из OperatorRole (D44).
    expect($operator->can('admin.prompt.update'))->toBeTrue()
        // Остальные enum-RBAC ресурсы (не исключённые) работают как раньше.
        ->and($operator->can('admin.platform.create'))->toBeTrue()
        ->and($operator->can('admin.ai_route.update'))->toBeTrue();
});

test('PromptResource: без права на промты viewAny/view/create denied несмотря на исключение из enum-каталога', function () {
    // Регресс-гвард на реальную дыру: exclude.resources снимает enum-RBAC
    // короткое замыкание для ВСЕХ 9 Filament-абилок Prompt, не только
    // update/delete. Если PromptPolicy не покрывает viewAny/view/create,
    // Filament молча дефолтит непокрытую абилку в allow
    // (`Filament\get_authorization_response()`), и любой аутентифицированный
    // пользователь без права видит/создаёт промты в обход `hasPermission()`.
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $prompt = Prompt::factory()->create(['is_protected' => false]);

    actingAs($analyst);

    expect(PromptResource::canViewAny())->toBeFalse()
        ->and(PromptResource::canView($prompt))->toBeFalse()
        ->and(PromptResource::canCreate())->toBeFalse();
});
