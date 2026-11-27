<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\ProcessRunState;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\WorkItem;
use App\Models\User;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Projection\RunProjector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

pest()->group('acquisition-operations');

uses(RefreshDatabase::class);

// review F1–F3: подтверждённая версия живёт между запросами модального окна, статус run берётся из
// авторитетного reducer, причина ограничена 200 символами как в WorkMutationRepository.

beforeEach(function (): void {
    artisan('guard:sync-roles');
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
});

function operatorControlFixtureWork(): WorkItem
{
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');

    return WorkItem::query()->findOrFail(ExecutionFixtures::listingWork()->value->value);
}

function operatorControlFixtureRun(): ProcessRun
{
    return ProcessRun::query()->findOrFail(LedgerFixtures::crawlBlock()['run']->value);
}

function operatorControlFixtureBlockedByFaultLimit(): WorkItem
{
    FinalizeFixtures::authority([StageCode::Listing]);
    FixtureProviderRecoveryResolver::install();
    $work = ExecutionFixtures::listingWork();
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault('a'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('b'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('c'));
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');

    return WorkItem::query()->findOrFail($work->value->value);
}

function operatorControlFixtureRow(string $table, string $id): stdClass
{
    return DB::table($table)->where('id', $id)->sole();
}

function operatorControlFixtureBump(string $table, string $id): int
{
    DB::table($table)->where('id', $id)->increment('transition_seq');

    return (int) DB::table($table)->where('id', $id)->value('transition_seq');
}

function operatorControlFixtureSuperAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);
    actingAs($admin);

    return $admin;
}

/** @return array<string, array{string, string}> */
function operatorControlFixtureActions(): array
{
    return [
        'cancel_work' => ['work', 'cancel_work'],
        'cancel_run' => ['run', 'cancel_run'],
    ];
}

/** @return array{Testable, Model, string} */
function operatorControlFixtureTarget(string $target): array
{
    if ($target === 'work') {
        $record = operatorControlFixtureWork();

        return [Livewire::test(ListWorkItems::class), $record, 'prc_process_work'];
    }
    $record = operatorControlFixtureRun();

    return [Livewire::test(ListProcessRuns::class), $record, 'prc_process_runs'];
}

function operatorControlFixtureApplied(string $target, string $id): bool
{
    return $target === 'work'
        ? operatorControlFixtureRow('prc_process_work', $id)->state === 'cancelled'
        : operatorControlFixtureRow('prc_process_runs', $id)->cancel_requested_at !== null;
}

// --- F1: версия, подтверждённая при открытии окна ---

test('устаревшее подтверждение не отменяет работу и не меняет версию', function (): void {
    $record = operatorControlFixtureWork();
    $page = Livewire::test(ListWorkItems::class)
        ->mountTableAction('cancel_work', $record)
        ->setTableActionData(['reason' => 'confirmed original version']);

    $concurrent = operatorControlFixtureBump('prc_process_work', $record->id);
    $page->callMountedTableAction();

    $after = operatorControlFixtureRow('prc_process_work', $record->id);
    expect($after->state)->toBe('ready')
        ->and((int) $after->transition_seq)->toBe($concurrent)
        ->and(DB::table('prc_process_transitions')->where('work_id', $record->id)->where('new_state', 'cancelled')->exists())->toBeFalse();
    $page->assertNotified('Команда не применена: version_conflict');
});

test('устаревшее подтверждение не отменяет запуск и не меняет версию', function (): void {
    $record = operatorControlFixtureRun();
    $page = Livewire::test(ListProcessRuns::class)
        ->mountTableAction('cancel_run', $record)
        ->setTableActionData(['reason' => 'confirmed original version']);

    $concurrent = operatorControlFixtureBump('prc_process_runs', $record->id);
    $page->callMountedTableAction();

    $after = operatorControlFixtureRow('prc_process_runs', $record->id);
    expect($after->cancel_requested_at)->toBeNull()
        ->and((int) $after->transition_seq)->toBe($concurrent);
    $page->assertNotified('Команда не применена: version_conflict');
});

test('устаревший retry не создаёт новую работу, а повторное открытие берёт свежую версию и свой commandId', function (): void {
    $work = operatorControlFixtureBlockedByFaultLimit();
    operatorControlFixtureSuperAdmin();
    $page = Livewire::test(ListWorkItems::class)
        ->mountTableAction('retry_handler_fault', $work)
        ->setTableActionData(['reason' => 'handler fixed']);

    $fresh = operatorControlFixtureBump('prc_process_work', $work->id);
    $page->callMountedTableAction();

    $after = operatorControlFixtureRow('prc_process_work', $work->id);
    expect($after->state)->toBe('blocked')
        ->and((int) $after->transition_seq)->toBe($fresh)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(0)
        ->and(DB::table('prc_process_transitions')->where('work_id', $work->id)->where('new_state', 'superseded')->exists())->toBeFalse();

    // Новое подтверждение после перечтения: версия и commandId выводятся из неё, а не из чтения при submit.
    Livewire::test(ListWorkItems::class)
        ->callTableAction('retry_handler_fault', $work->refresh(), ['reason' => 'handler fixed'])
        ->assertHasNoTableActionErrors();

    $details = json_decode((string) DB::table('prc_process_transitions')->where('work_id', $work->id)->where('new_state', 'superseded')->value('details'), true);
    expect(operatorControlFixtureRow('prc_process_work', $work->id)->state)->toBe('superseded')
        ->and($details['command_id'])->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'process-retry:'.$work->id.':'.$fresh)->toString());
});

test('retry использует commandId от подтверждённой версии, а не от версии при submit', function (): void {
    $work = operatorControlFixtureBlockedByFaultLimit();
    $confirmed = (int) $work->transition_seq;
    operatorControlFixtureSuperAdmin();

    Livewire::test(ListWorkItems::class)
        ->callTableAction('retry_handler_fault', $work, ['reason' => 'handler fixed'])
        ->assertHasNoTableActionErrors();

    $details = json_decode((string) DB::table('prc_process_transitions')->where('work_id', $work->id)->where('new_state', 'superseded')->value('details'), true);
    expect($details['command_id'])->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'process-retry:'.$work->id.':'.$confirmed)->toString());
});

test('версию в данных формы нельзя подделать или перенести', function (string $target, string $action, string $forgery): void {
    [$page, $record, $table] = operatorControlFixtureTarget($target);
    $id = (string) $record->getKey();
    $seq = (int) $record->transition_seq;
    $operator = (string) auth()->id();

    $token = match ($forgery) {
        'unsigned current version' => (string) $seq,
        'zero signature' => $seq.'.'.str_repeat('0', 64),
        'non-string' => ['version' => $seq],
        'other operator' => OperatorActionInput::issue($action, $id, $seq, $operator.'0'),
        'other action' => OperatorActionInput::issue($action === 'cancel_work' ? 'cancel_run' : 'cancel_work', $id, $seq, $operator),
        'other record' => OperatorActionInput::issue($action, (string) Uuid::uuid4(), $seq, $operator),
        'rebound version' => ($seq + 1).'.'.explode('.', OperatorActionInput::issue($action, $id, $seq, $operator))[1],
    };
    $page->mountTableAction($action, $record)
        ->setTableActionData(['reason' => 'forged version'])
        ->set('mountedActions.0.data.'.OperatorActionInput::FIELD, $token)
        ->callMountedTableAction();

    expect(operatorControlFixtureApplied($target, $id))->toBeFalse()
        ->and((int) operatorControlFixtureRow($table, $id)->transition_seq)->toBe($seq);
    $page->assertNotified('Подтверждение недействительно: откройте окно действия заново');
})->with(operatorControlFixtureActions())->with([
    'unsigned current version', 'zero signature', 'non-string', 'other operator', 'other action', 'other record', 'rebound version',
]);

test('токен версии: подписанная версия возвращается, любое изменение отвергается', function (): void {
    $token = OperatorActionInput::issue('cancel_work', 'work-1', 7, '10');

    expect(OperatorActionInput::version('cancel_work', 'work-1', $token, '10'))->toBe(7)
        ->and(OperatorActionInput::version('cancel_work', 'work-1', '8'.substr($token, 1), '10'))->toBeNull()
        ->and(OperatorActionInput::version('cancel_work', 'work-2', $token, '10'))->toBeNull()
        ->and(OperatorActionInput::version('cancel_run', 'work-1', $token, '10'))->toBeNull()
        ->and(OperatorActionInput::version('cancel_work', 'work-1', $token, '11'))->toBeNull()
        ->and(OperatorActionInput::version('cancel_work', 'work-1', '7', '10'))->toBeNull()
        ->and(OperatorActionInput::version('cancel_work', 'work-1', null, '10'))->toBeNull()
        ->and(OperatorActionInput::version('cancel_work', 'work-1', strtoupper($token), '10'))->toBeNull();
});

// --- F2: состояние run из авторитетного reducer ---

/** @return array{ProcessUuid, ProcessRunState} */
function operatorControlFixtureRunWithRequired(?string $workState, string $discovery, bool $cancelled = false): array
{
    $fixture = LedgerFixtures::crawlBlock();
    if ($workState !== null) {
        $overrides = ['state' => $workState];
        if ($workState !== 'succeeded') {
            $overrides['reason_code'] = 'review_fixture';
        }
        $work = LedgerFixtures::insertRawWork($fixture['block'], $overrides);
        DB::table('prc_process_run_work')->insert(['run_id' => $fixture['run']->value,
            'work_id' => $work->value, 'relation' => 'created', 'accounting_role' => 'required']);
    }
    if ($discovery !== 'collecting') {
        DB::table('prc_process_runs')->where('id', $fixture['run']->value)->update([
            'discovery_outcome' => $discovery, 'discovery_closed_at' => DB::raw('clock_timestamp()'),
            'discovery_reason_code' => $discovery === 'incomplete' ? 'source_cap' : null,
        ]);
    }
    if ($cancelled) {
        DB::table('prc_process_runs')->where('id', $fixture['run']->value)->update(['cancel_requested_at' => DB::raw('clock_timestamp()')]);
    }

    return [$fixture['run'], app(RunProjector::class)->summary(new ProcessUuid($fixture['run']->value))->state];
}

/** @return array{string, string} */
function operatorControlFixtureRunCell(ProcessUuid $id): array
{
    $page = Livewire::test(ListProcessRuns::class)->instance();
    $column = $page->getTable()->getColumn('status_text')->record($page->getTableRecord($id->value));

    return [(string) $column->getState(), (string) $column->getColor($column->getState())];
}

test('таблица запусков показывает состояние и цвет авторитетного reducer', function (?string $work, string $discovery, bool $cancelled, ProcessRunState $state, string $label, string $color): void {
    [$id, $authoritative] = operatorControlFixtureRunWithRequired($work, $discovery, $cancelled);

    expect($authoritative)->toBe($state)
        ->and(operatorControlFixtureRunCell($id))->toBe([$label, $color]);
})->with([
    'запрошен' => [null, 'collecting', false, ProcessRunState::Requested, 'Запрошен', 'gray'],
    'выполняется' => ['ready', 'complete', false, ProcessRunState::Running, 'Выполняется', 'gray'],
    'ждёт' => ['blocked', 'complete', false, ProcessRunState::Waiting, 'Ждёт', 'warning'],
    'завершён' => ['succeeded', 'complete', false, ProcessRunState::Completed, 'Завершён', 'success'],
    'отменён' => ['ready', 'complete', true, ProcessRunState::Cancelled, 'Отменён оператором', 'gray'],
    'failed required work' => ['failed_item', 'complete', false, ProcessRunState::CompletedWithIssues, 'Завершён с проблемами', 'warning'],
    'cancelled required work' => ['cancelled', 'complete', false, ProcessRunState::CompletedWithIssues, 'Завершён с проблемами', 'warning'],
    'superseded required work без successor' => ['superseded', 'complete', false, ProcessRunState::CompletedWithIssues, 'Завершён с проблемами', 'warning'],
    'неполный discovery' => ['succeeded', 'incomplete', false, ProcessRunState::CompletedWithIssues, 'Завершён с проблемами', 'warning'],
]);

// --- F3: причина не длиннее 200 символов ---

test('причина на границе 200 символов принимается, 201 отклоняется формой без исключения', function (string $target, string $action, string $reason, bool $accepted): void {
    [$page, $record] = operatorControlFixtureTarget($target);

    $page->callTableAction($action, $record, ['reason' => $reason]);

    if ($accepted) {
        $page->assertHasNoTableActionErrors();
    } else {
        $page->assertHasTableActionErrors(['reason']);
    }
    expect(operatorControlFixtureApplied($target, (string) $record->getKey()))->toBe($accepted);
})->with(operatorControlFixtureActions())->with([
    '200 ASCII' => [str_repeat('a', 200), true],
    '200 кириллицы' => [str_repeat('я', 200), true],
    '201 ASCII' => [str_repeat('a', 201), false],
    '201 кириллицы' => [str_repeat('я', 201), false],
]);

test('недопустимая причина, прошедшая форму, возвращается уведомлением, а не исключением', function (string $target, string $action): void {
    [$page, $record] = operatorControlFixtureTarget($target);

    $page->callTableAction($action, $record, ['reason' => "bad\0reason"])
        ->assertNotified('Причина обязательна и не длиннее 200 символов');

    expect(operatorControlFixtureApplied($target, (string) $record->getKey()))->toBeFalse();
})->with(operatorControlFixtureActions());

test('retry: причина 201 символ отклоняется формой, 200 принимается', function (): void {
    $work = operatorControlFixtureBlockedByFaultLimit();
    operatorControlFixtureSuperAdmin();

    Livewire::test(ListWorkItems::class)
        ->callTableAction('retry_handler_fault', $work, ['reason' => str_repeat('a', 201)])
        ->assertHasTableActionErrors(['reason']);
    expect(operatorControlFixtureRow('prc_process_work', $work->id)->state)->toBe('blocked');

    Livewire::test(ListWorkItems::class)
        ->callTableAction('retry_handler_fault', $work, ['reason' => str_repeat('a', 200)])
        ->assertHasNoTableActionErrors();
    expect(operatorControlFixtureRow('prc_process_work', $work->id)->state)->toBe('superseded');
});

test('retry скрыт и не вызывается оператором без права retry_handler_fault', function (): void {
    $work = operatorControlFixtureBlockedByFaultLimit();

    Livewire::test(ListWorkItems::class)
        ->assertTableActionHidden('retry_handler_fault', $work)
        ->call('mountTableAction', 'retry_handler_fault', $work->id)
        ->set('mountedActions.0.data.reason', 'handler fixed')
        ->call('callMountedAction');

    expect(operatorControlFixtureRow('prc_process_work', $work->id)->state)->toBe('blocked');
});
