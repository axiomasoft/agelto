<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\CreateStatusMapping;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\EditStatusMapping;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\ListStatusMappings;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(fn () => artisan('guard:sync-roles'));

function statusMappingResourceOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    return $operator;
}

function statusMappingResourceSuperAdmin(): User
{
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);

    return $superAdmin;
}

test('оператор открывает список маппингов статусов', function (): void {
    actingAs(statusMappingResourceOperator());

    Livewire::test(ListStatusMappings::class)
        ->assertSuccessful();
});

test('оператор открывает страницу правки маппинга статуса', function (): void {
    actingAs(statusMappingResourceOperator());

    $mapping = StatusMapping::factory()->create();

    Livewire::test(EditStatusMapping::class, ['record' => $mapping->getKey()])
        ->assertSuccessful();
});

test('только superadmin создаёт platform и registry applicability', function (): void {
    $platform = Platform::factory()->create();
    $registry = Registry::factory()->for($platform)->create();

    actingAs(statusMappingResourceOperator());
    Livewire::test(CreateStatusMapping::class)
        ->assertForbidden();

    actingAs(statusMappingResourceSuperAdmin());
    Livewire::test(CreateStatusMapping::class)
        ->assertFormFieldExists('applicability')
        ->assertFormFieldExists('platform_id')
        ->assertFormFieldExists('registry_id')
        ->fillForm([
            'applicability' => StatusMappingApplicability::Platform->value,
            'platform_id' => $platform->id,
            'source_stage' => 'Площадочный этап',
            'canonical_status' => ProcurementStatus::Evaluation->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateStatusMapping::class)
        ->fillForm([
            'applicability' => StatusMappingApplicability::Registry->value,
            'selected_platform_id' => $platform->id,
            'registry_id' => $registry->id,
            'source_stage' => 'Реестровый этап',
            'canonical_status' => ProcurementStatus::Bidding->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StatusMapping::query()->where('platform_id', $platform->id)->where('source_stage', 'Площадочный этап')->exists())->toBeTrue()
        ->and(StatusMapping::query()->where('registry_id', $registry->id)->where('source_stage', 'Реестровый этап')->exists())->toBeTrue();
});

test('форма отклоняет duplicate applicability до записи', function (): void {
    $platform = Platform::factory()->create();
    StatusMapping::factory()->forPlatform($platform)->create(['source_stage' => 'Дубликат']);

    actingAs(statusMappingResourceSuperAdmin());

    Livewire::test(CreateStatusMapping::class)
        ->fillForm([
            'applicability' => StatusMappingApplicability::Platform->value,
            'platform_id' => $platform->id,
            'source_stage' => 'Дубликат',
            'canonical_status' => ProcurementStatus::Completed->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['source_stage']);
});

test('фильтр только unknown скрывает известные маппинги', function (): void {
    actingAs(statusMappingResourceOperator());

    $registry = Registry::factory()->create();
    $unknown = StatusMapping::factory()->for($registry)->create([
        'canonical_status' => ProcurementStatus::Unknown->value,
    ]);
    $known = StatusMapping::factory()->for($registry)->create([
        'canonical_status' => ProcurementStatus::Completed->value,
    ]);

    Livewire::test(ListStatusMappings::class)
        ->filterTable('only_unknown')
        ->assertCanSeeTableRecords([$unknown])
        ->assertCanNotSeeTableRecords([$known]);
});

test('таблица предоставляет все фильтры applicability', function (): void {
    actingAs(statusMappingResourceOperator());

    Livewire::test(ListStatusMappings::class)
        ->assertTableColumnExists('platform.name')
        ->assertTableColumnExists('applicability')
        ->assertTableFilterExists('platform')
        ->assertTableFilterExists('registry_id')
        ->assertTableFilterExists('applicability')
        ->assertTableFilterExists('canonical_status')
        ->assertTableFilterExists('only_unknown');
});

test('оператор меняет только канонический статус', function (): void {
    actingAs(statusMappingResourceOperator());

    $mapping = StatusMapping::factory()->create([
        'source_stage' => 'Неизвестный этап площадки',
        'canonical_status' => ProcurementStatus::Unknown->value,
    ]);

    Livewire::test(EditStatusMapping::class, ['record' => $mapping->getKey()])
        ->assertFormFieldExists('registry_id', function (Select $field): bool {
            return $field->isDisabled();
        })
        ->assertFormFieldExists('source_stage', function (TextInput $field): bool {
            return $field->isDisabled();
        })
        ->fillForm([
            'source_stage' => 'Подменённый этап',
            'canonical_status' => ProcurementStatus::Completed->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $mapping->refresh();

    expect($mapping->source_stage)->toBe('Неизвестный этап площадки');
    expect($mapping->canonical_status)->toBe(ProcurementStatus::Completed->value);
});
