<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Crawler\Runs\RunResource;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Filament\Facades\Filament;

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
        ->and($superAdmin->can('admin.role.delete'))->toBeTrue();
});

test('view-only ресурсы Run и Procurement не имеют create/edit страниц', function () {
    expect(array_keys(RunResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(ProcurementResource::getPages()))->toBe(['index', 'view']);
});
