<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\EditStatusMapping;
use App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\ListStatusMappings;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\User;
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

test('оператор меняет только канонический статус', function (): void {
    actingAs(statusMappingResourceOperator());

    $mapping = StatusMapping::factory()->create([
        'source_stage' => 'Неизвестный этап площадки',
        'canonical_status' => ProcurementStatus::Unknown->value,
    ]);

    Livewire::test(EditStatusMapping::class, ['record' => $mapping->getKey()])
        ->assertFormFieldExists('registry_id', function (TextInput $field): bool {
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
