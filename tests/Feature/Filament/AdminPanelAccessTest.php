<?php

declare(strict_types=1);

use App\Actions\Admin\ResetAdminPasswordAction;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Resources\Procurement\Ai\Prompts\PromptResource;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Filament\Widgets\LlmSpendChartWidget;
use App\Guards\Admin\Permissions\DocumentationPermission;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\Prompt;
use App\Models\User;
use AzGuard\Filament\Resources\RoleGrantResource\Pages\ListRoleGrants;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

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
        ->and($operator->can('admin.process_control.cancel_run'))->toBeTrue()
        // прогоны не создаёт/не удаляет, реестр закупок и роли — вне доступа
        ->and($operator->can('admin.process_run.create'))->toBeFalse()
        ->and($operator->can('admin.procurement.create'))->toBeFalse()
        ->and($operator->can('admin.role.create'))->toBeFalse();
});

test('аналитик видит продуктовые реестры только на чтение', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);

    expect($analyst->can('admin.procurement.view_any'))->toBeTrue()
        ->and($analyst->can('admin.process_run.view_any'))->toBeTrue()
        // ни мутаций реестра, ни операторской конфигурации
        ->and($analyst->can('admin.procurement.delete'))->toBeFalse()
        ->and($analyst->can('admin.process_control.cancel_run'))->toBeFalse()
        ->and($analyst->can('admin.platform.view_any'))->toBeFalse();
});

test('суперадмин проходит любой gate через wildcard', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    expect($superAdmin->can('admin.procurement.delete'))->toBeTrue()
        ->and($superAdmin->can('admin.role.delete'))->toBeTrue()
        ->and($superAdmin->hasPermission(permission: DocumentationPermission::View, panelId: 'admin'))->toBeTrue();
});

test('прямые admin URL повторно проверяют роль, а не только видимость navigation', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    actingAs($analyst);
    $this->get('/admin/procurement/platform/platforms')->assertForbidden();

    actingAs($operator);
    $this->get('/admin/azguard-roles')->assertForbidden();

    actingAs($superAdmin);
    $this->get('/admin/azguard-roles')->assertOk();
});

test('Dashboard, FinalPromptPage и LlmSpendChartWidget авторизуются через AzGuard для оператора и аналитика и закрыты без права', function () {
    $unprivileged = User::factory()->create();
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    actingAs($unprivileged);
    expect(Dashboard::canAccess())->toBeFalse()
        ->and(FinalPromptPage::canAccess())->toBeFalse()
        ->and(LlmSpendChartWidget::canView())->toBeFalse();

    actingAs($analyst);
    expect(Dashboard::canAccess())->toBeTrue()
        ->and(FinalPromptPage::canAccess())->toBeTrue()
        ->and(LlmSpendChartWidget::canView())->toBeTrue();

    actingAs($operator);
    expect(Dashboard::canAccess())->toBeTrue()
        ->and(FinalPromptPage::canAccess())->toBeTrue()
        ->and(LlmSpendChartWidget::canView())->toBeTrue();
});

test('view-only ресурсы Run и Procurement не имеют create/edit страниц', function () {
    expect(array_keys(ProcessRunResource::getPages()))->toBe(['index'])
        ->and(array_keys(ProcurementResource::getPages()))->toBe(['index', 'view']);
});

test('PromptResource вне enum-RBAC каталога, остальные ресурсы и коарс-право на промты не задеты', function () {
    expect(config('azguard-filament.exclude.resources'))->toContain(PromptResource::class);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    // Коарс-право на Prompt по-прежнему каталогизировано и грантуется —
    // exclude снимает только per-record enum-RBAC короткое замыкание,
    // не убирает PromptPermission из OperatorRole.
    expect($operator->can('admin.prompt.update'))->toBeTrue()
        // Остальные enum-RBAC ресурсы (не исключённые) работают как раньше.
        ->and($operator->can('admin.platform.create'))->toBeTrue()
        ->and($operator->can('admin.ai_route.update'))->toBeTrue();
});

test('PromptResource: без права на промты viewAny/view/create denied несмотря на исключение из enum-каталога', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $prompt = Prompt::factory()->create(['is_protected' => false]);

    actingAs($analyst);

    expect(PromptResource::canViewAny())->toBeFalse()
        ->and(PromptResource::canView($prompt))->toBeFalse()
        ->and(PromptResource::canCreate())->toBeFalse();
});

test('отзыв роли в RoleUsersRelationManager и удаление RolePermission сразу сбрасывают кэш прав', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    $target = User::factory()->create();
    $target->assignRole(SuperAdminRole::class);

    expect($target->fresh()->isSuperAdmin())->toBeTrue();

    actingAs($superAdmin);

    $grant = $target->guard('admin')->roleGrants()->first();
    expect($grant)->not->toBeNull();

    Livewire::test(ListRoleGrants::class)
        ->callTableAction('revoke', 'role:'.$grant->getKey());

    expect($target->fresh()->isSuperAdmin())->toBeFalse();

    $dbUser = User::factory()->create();
    $dbUser->guard('admin')->grantPermission('admin.procurement.view_any');

    expect($dbUser->fresh()->can('admin.procurement.view_any'))->toBeTrue();

    $dbUser->guard('admin')->revokePermission('admin.procurement.view_any');

    expect($dbUser->fresh()->can('admin.procurement.view_any'))->toBeFalse();
});

test('сброс пароля администратора ротирует remember_token и отклоняет короткий пароль', function () {
    $admin = User::factory()->create([
        'email' => 'reset-check@example.test',
        'password' => 'old-valid-password',
        'remember_token' => 'fixed-remember-token',
    ]);

    expect(fn () => app(ResetAdminPasswordAction::class)->run('reset-check@example.test', 'short'))
        ->toThrow(InvalidArgumentException::class);

    app(ResetAdminPasswordAction::class)->run('reset-check@example.test', 'new-valid-password');

    $fresh = $admin->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->remember_token)->not->toBe('fixed-remember-token')
        ->and($fresh->remember_token)->not->toBeNull();
});
