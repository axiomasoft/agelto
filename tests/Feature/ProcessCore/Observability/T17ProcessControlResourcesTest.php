<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Incidents\IncidentResource;
use App\Filament\Resources\Procurement\Process\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Filament\Resources\Procurement\Process\Work\WorkItemResource;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Process\ControlScope;
use App\Models\Procurement\Process\Incident;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\WorkItem;
use App\Models\User;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
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

function uiBlockedByFaultLimit(): WorkId
{
    FinalizeFixtures::authority([StageCode::Listing]);
    FixtureProviderRecoveryResolver::install();
    $work = ExecutionFixtures::listingWork();
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault('a'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('b'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('c'));

    return $work;
}

test('T17 EXR-01: полный sidebar админки на промежуточном baseline (legacy badge + новый Process UI) рендерится без undefined-table', function (): void {
    SourceIncident::factory()->create();
    $admin = processUiUser(SuperAdminRole::class);

    $html = actingAs($admin)->get(ProcessRunResource::getUrl())->assertOk()->getContent();

    expect($html)->toContain(SourceIncidentResource::getNavigationLabel())
        ->and($html)->toContain('Процессы: запуски')
        ->and($html)->toContain('Процессы: работа')
        ->and($html)->toContain('Процессы: управление')
        ->and($html)->toContain('Процессы: инциденты');
    actingAs(processUiUser(OperatorRole::class))->get(WorkItemResource::getUrl())->assertOk();
    actingAs(processUiUser(AnalystRole::class))->get(WorkItemResource::getUrl())->assertForbidden();
    actingAs(processUiUser(AnalystRole::class))->get(ProcessRunResource::getUrl())->assertForbidden();
});

test('T17 drilldown work: причина и next retry видны; CancelWork через таблицу с версией и audit; terminal без действия', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $work = ExecutionFixtures::listingWork();
    $operator = processUiUser(OperatorRole::class);
    actingAs($operator);
    $record = WorkItem::query()->findOrFail($work->value->value);

    Livewire::test(ListWorkItems::class)
        ->assertCanSeeTableRecords([$record])
        ->assertTableActionVisible('cancel_work', $record)
        ->assertTableActionHidden('retry_handler_fault', $record)
        ->callTableAction('cancel_work', $record, ['reason' => 'ui cancel'])
        ->assertHasNoTableActionErrors();

    $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    $audit = DB::table('prc_process_transitions')->where('work_id', $work->value->value)->orderByDesc('entity_seq')->first();
    expect($row->state)->toBe('cancelled')
        ->and([$audit->new_state, $audit->actor_kind, $audit->actor_id])->toBe(['cancelled', 'operator', $operator->id]);

    Livewire::test(ListWorkItems::class)->assertTableActionHidden('cancel_work', $record->refresh());
});

test('T17 EXR-02 UI: RetryHandlerFaultedWork виден только суперадмину на blocked(handler_fault_limit); оператор не видит и не вызывает', function (): void {
    $work = uiBlockedByFaultLimit();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $record = WorkItem::query()->findOrFail($work->value->value);
    expect($record->reason_code)->toBe('handler_fault_limit');

    $operator = processUiUser(OperatorRole::class);
    actingAs($operator);
    Livewire::test(ListWorkItems::class)
        ->assertTableActionHidden('retry_handler_fault', $record)
        ->assertTableActionVisible('cancel_work', $record);

    $admin = processUiUser(SuperAdminRole::class);
    actingAs($admin);
    Livewire::test(ListWorkItems::class)
        ->assertTableActionVisible('retry_handler_fault', $record)
        ->callTableAction('retry_handler_fault', $record, ['reason' => 'handler fixed'])
        ->assertHasNoTableActionErrors();

    expect(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('superseded')
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(1)
        ->and(DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('actor_id', $admin->id)->exists())->toBeTrue();

    // После retry прежний work superseded: действие скрыто, второй active intent невозможен.
    Livewire::test(ListWorkItems::class)->assertTableActionHidden('retry_handler_fault', $record->refresh());
    expect(DB::table('prc_operation_intents')->where('state', 'active')->count())->toBe(1);
});

test('T17 drilldown runs: CancelRun через таблицу отменяет намерение run, но не shared work второго run', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $cancelled = LedgerFixtures::crawlBlock()['run'];
    $other = LedgerFixtures::crawlBlock()['run'];
    $store = app(WorkStoreRepository::class);
    $store->linkRun($cancelled, $work, RunWorkRelation::Created, AccountingRole::Required);
    $store->linkRun($other, $work, RunWorkRelation::Discovered, AccountingRole::Required);
    $operator = processUiUser(OperatorRole::class);
    actingAs($operator);
    $record = ProcessRun::query()->findOrFail($cancelled->value);

    Livewire::test(ListProcessRuns::class)
        ->assertCanSeeTableRecords([$record])
        ->assertTableActionVisible('cancel_run', $record)
        ->callTableAction('cancel_run', $record, ['reason' => 'ui cancel run'])
        ->assertHasNoTableActionErrors();

    expect(DB::table('prc_process_runs')->where('id', $cancelled->value)->value('cancel_requested_at'))->not->toBeNull()
        ->and(DB::table('prc_process_runs')->where('id', $other->value)->value('cancel_requested_at'))->toBeNull()
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->not->toBe('cancelled');

    Livewire::test(ListProcessRuns::class)->assertTableActionHidden('cancel_run', $record->refresh());
});
