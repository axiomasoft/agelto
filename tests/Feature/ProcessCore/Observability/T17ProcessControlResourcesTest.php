<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Incidents\IncidentResource;
use App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Process\ControlScope;
use App\Models\Procurement\Process\Incident;
use App\Models\User;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

pest()->group('t17', 'p117');

// Process UI читает ledger и пишет только через ProcessOperatorCommands: версия строки, reason, audit.

beforeEach(fn () => artisan('guard:sync-roles'));

function processUiUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('T17 UI: оператор видит области и инциденты, аналитик получает 403', function (): void {
    actingAs(processUiUser(AnalystRole::class))->get(ControlScopeResource::getUrl())->assertForbidden();
    actingAs(processUiUser(AnalystRole::class))->get(IncidentResource::getUrl())->assertForbidden();

    actingAs(processUiUser(OperatorRole::class))->get(ControlScopeResource::getUrl())->assertOk();
    actingAs(processUiUser(OperatorRole::class))->get(IncidentResource::getUrl())->assertOk();
});

test('T17 UI: Pause/Resume через таблицу меняют версию и пишут audit с actor; режим определяет видимость действия', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $operator = processUiUser(OperatorRole::class);
    actingAs($operator);
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    $version = (int) $scope->control_version;

    Livewire::test(ListControlScopes::class)
        ->assertTableActionVisible('pause', $scope)
        ->assertTableActionHidden('resume', $scope)
        ->callTableAction('pause', $scope, ['reason' => 'ui pause'])
        ->assertHasNoTableActionErrors();

    $paused = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->first();
    expect([$paused->desired_mode, (int) $paused->control_version, $paused->changed_by, $paused->change_reason])->toBe(['paused', $version + 1, $operator->id, 'ui pause']);

    Livewire::test(ListControlScopes::class)
        ->assertTableActionHidden('pause', $scope->refresh())
        ->assertTableActionVisible('resume', $scope)
        ->callTableAction('resume', $scope, ['reason' => 'ui resume']);

    expect(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('enabled')
        ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->where('actor_id', $operator->id)->count())->toBe(2);
});

test('T17 UI: Acknowledge инцидента доступен для open, пишет audit и прячется после принятия', function (): void {
    $id = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(fn () => app(IncidentRepository::class)->open(
        LedgerFixtures::hash('ui-incident'), 'acquisition', 'operator_test', ['k' => 1], $id, LedgerFixtures::uuid(), LedgerFixtures::uuid(),
    ));
    $operator = processUiUser(OperatorRole::class);
    actingAs($operator);
    $incident = Incident::query()->findOrFail($id->value);

    Livewire::test(ListIncidents::class)
        ->assertTableActionVisible('acknowledge', $incident)
        ->callTableAction('acknowledge', $incident, ['reason' => 'ui ack']);

    $row = DB::table('prc_process_incidents')->where('id', $id->value)->sole();
    expect([$row->state, $row->acknowledged_by])->toBe(['acknowledged', $operator->id]);

    Livewire::test(ListIncidents::class)->assertTableActionHidden('acknowledge', $incident->refresh());
    expect(new ProcessUuid($id->value))->toBeInstanceOf(ProcessUuid::class);
});
