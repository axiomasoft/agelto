<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Process\ControlScopes\Pages\ListControlScopes;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Process\ControlScope;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

pest()->group('t17', 'p117');

beforeEach(function (): void {
    artisan('guard:sync-roles');
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
});

function t17ControlScope(string $action): ControlScope
{
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $scope = ControlScope::query()->where('scope_key', 'acquisition')->sole();
    if ($action === 'resume') {
        app(ProcessOperatorCommands::class)->pauseScope(auth()->user(), $scope->scope_key, (int) $scope->control_version, 'initial incident');
    }

    return $scope->refresh();
}

test('Pause/Resume: устаревшее окно после двух чужих команд не меняет новую версию или audit', function (string $action): void {
    $scope = t17ControlScope($action);
    $confirmed = (int) $scope->control_version;
    $page = Livewire::test(ListControlScopes::class)
        ->mountTableAction($action, $scope)
        ->setTableActionData(['reason' => 'old confirmation']);

    $other = User::factory()->create();
    $other->assignRole(OperatorRole::class);
    $commands = app(ProcessOperatorCommands::class);
    if ($action === 'resume') {
        $commands->resumeScope($other, $scope->scope_key, $confirmed, 'old incident resolved');
        $commands->pauseScope($other, $scope->scope_key, $confirmed + 1, 'new incident');
    } else {
        $commands->pauseScope($other, $scope->scope_key, $confirmed, 'new maintenance');
        $commands->resumeScope($other, $scope->scope_key, $confirmed + 1, 'maintenance completed');
    }
    $before = DB::table('prc_control_scopes')->where('id', $scope->id)->sole();
    $audit = DB::table('prc_process_transitions')->where('control_id', $scope->id)->orderBy('entity_seq')->get()->all();

    $page->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertNotified('Команда не применена: version_conflict');

    expect(DB::table('prc_control_scopes')->where('id', $scope->id)->sole())->toEqual($before)
        ->and((int) $before->control_version)->toBe($confirmed + 2)
        ->and(DB::table('prc_process_transitions')->where('control_id', $scope->id)->orderBy('entity_seq')->get()->all())->toEqual($audit);

    // Перечтение и новое подтверждение разрешают именно новую версию.
    Livewire::test(ListControlScopes::class)
        ->callTableAction($action, $scope->refresh(), ['reason' => 'fresh confirmation'])
        ->assertHasNoTableActionErrors();
    expect((int) $scope->refresh()->control_version)->toBe($confirmed + 3)
        ->and($scope->change_reason)->toBe('fresh confirmation');
})->with(['pause', 'resume']);

test('Pause/Resume: подделка и перенос подписанного подтверждения отвергаются без записи', function (string $action, string $forgery): void {
    $scope = t17ControlScope($action);
    $version = (int) $scope->control_version;
    $token = match ($forgery) {
        'unsigned' => (string) $version,
        'other operator' => OperatorActionInput::issue($action, $scope->id, $version, (string) (auth()->id() + 1)),
        'other action' => OperatorActionInput::issue($action === 'pause' ? 'resume' : 'pause', $scope->id, $version),
        'other record' => OperatorActionInput::issue($action, 'another-scope', $version),
        'rebound version' => ($version + 1).'.'.explode('.', OperatorActionInput::issue($action, $scope->id, $version))[1],
        'non-string' => ['version' => $version],
    };
    $auditCount = DB::table('prc_process_transitions')->count();

    Livewire::test(ListControlScopes::class)
        ->mountTableAction($action, $scope)
        ->setTableActionData(['reason' => 'forged confirmation'])
        ->set('mountedActions.0.data.'.OperatorActionInput::FIELD, $token)
        ->callMountedTableAction()
        ->assertNotified('Подтверждение недействительно: откройте окно действия заново');

    expect((int) $scope->refresh()->control_version)->toBe($version)
        ->and(DB::table('prc_process_transitions')->count())->toBe($auditCount);
})->with(['pause', 'resume'])->with(['unsigned', 'other operator', 'other action', 'other record', 'rebound version', 'non-string']);

test('Pause/Resume: форма принимает 200 символов причины и отвергает 201', function (string $action, string $character, int $length): void {
    $scope = t17ControlScope($action);
    $version = (int) $scope->control_version;
    $reason = str_repeat($character, $length);
    $page = Livewire::test(ListControlScopes::class)->callTableAction($action, $scope, ['reason' => $reason]);

    if ($length === 200) {
        $page->assertHasNoTableActionErrors();
        expect((int) $scope->refresh()->control_version)->toBe($version + 1)
            ->and($scope->change_reason)->toBe($reason);
    } else {
        $page->assertHasTableActionErrors(['reason']);
        expect((int) $scope->refresh()->control_version)->toBe($version);
    }
})->with(['pause', 'resume'])->with(['a', 'я'])->with([200, 201]);

test('Pause/Resume: недопустимая причина возвращается уведомлением без исключения или записи', function (string $action): void {
    $scope = t17ControlScope($action);
    $before = DB::table('prc_control_scopes')->where('id', $scope->id)->sole();
    $auditCount = DB::table('prc_process_transitions')->count();

    Livewire::test(ListControlScopes::class)
        ->callTableAction($action, $scope, ['reason' => "bad\0reason"])
        ->assertNotified('Причина обязательна и не длиннее 200 символов');

    expect(DB::table('prc_control_scopes')->where('id', $scope->id)->sole())->toEqual($before)
        ->and(DB::table('prc_process_transitions')->count())->toBe($auditCount);
})->with(['pause', 'resume']);

test('Pause/Resume: шлюз отвергает недопустимую причину до изменения режима и audit', function (string $action, string $reason): void {
    $scope = t17ControlScope($action);
    $version = (int) $scope->control_version;
    $auditCount = DB::table('prc_process_transitions')->count();
    $commands = app(ProcessOperatorCommands::class);

    expect(fn () => $action === 'pause'
        ? $commands->pauseScope(auth()->user(), $scope->scope_key, $version, $reason)
        : $commands->resumeScope(auth()->user(), $scope->scope_key, $version, $reason))->toThrow(InvalidArgumentException::class);

    expect((int) $scope->refresh()->control_version)->toBe($version)
        ->and(DB::table('prc_process_transitions')->count())->toBe($auditCount);
})->with(['pause', 'resume'])->with(['  ', str_repeat('a', 201), str_repeat('я', 201), "bad\0reason"]);

test('Pause/Resume: последующие изменения сохраняют каждую причину и actor в неизменяемой истории', function (): void {
    $scope = t17ControlScope('pause');
    $version = (int) $scope->control_version;
    $operator = auth()->user();
    $other = User::factory()->create();
    $other->assignRole(OperatorRole::class);
    $commands = app(ProcessOperatorCommands::class);

    expect($commands->pauseScope($operator, $scope->scope_key, $version, 'incident A')->status)->toBe(OperatorCommandStatus::Applied);
    $original = DB::table('prc_process_transitions')->where('control_id', $scope->id)->sole();
    expect($commands->resumeScope($other, $scope->scope_key, $version + 1, 'incident A resolved')->status)->toBe(OperatorCommandStatus::Applied)
        ->and($commands->pauseScope($operator, $scope->scope_key, $version + 2, 'incident B')->status)->toBe(OperatorCommandStatus::Applied);

    $history = DB::table('prc_process_transitions')->where('control_id', $scope->id)->orderBy('entity_seq')->get();
    expect($history->first())->toEqual($original)
        ->and($history->map(fn (object $row): array => [
            (int) $row->entity_seq, $row->previous_state, $row->new_state, $row->actor_kind, $row->actor_id,
            (int) $row->details_schema_version, json_decode((string) $row->details, true, flags: JSON_THROW_ON_ERROR),
        ])->all())->toBe([
            [$version + 1, 'enabled', 'paused', 'operator', $operator->id, 1, ['reason' => 'incident A']],
            [$version + 2, 'paused', 'enabled', 'operator', $other->id, 1, ['reason' => 'incident A resolved']],
            [$version + 3, 'enabled', 'paused', 'operator', $operator->id, 1, ['reason' => 'incident B']],
        ])
        ->and($scope->refresh()->change_reason)->toBe('incident B');

    // Отказ и no-op не перезаписывают ни последнюю причину, ни историю.
    expect($commands->resumeScope($other, $scope->scope_key, $version + 1, 'stale')->status)->toBe(OperatorCommandStatus::VersionConflict)
        ->and($commands->pauseScope($operator, $scope->scope_key, $version + 3, 'no-op')->status)->toBe(OperatorCommandStatus::Unchanged)
        ->and(DB::table('prc_process_transitions')->where('control_id', $scope->id)->orderBy('entity_seq')->get()->all())->toEqual($history->all())
        ->and($scope->refresh()->change_reason)->toBe('incident B');
});
