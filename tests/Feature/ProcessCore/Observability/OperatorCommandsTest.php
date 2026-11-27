<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\OperatorCommandStatus;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Services\Procurement\Process\Diagnostics\ProcessOperatorCommands;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

use function Pest\Laravel\artisan;

pest()->group('acquisition-operations');

// Typed operator commands на реальном ledger: RBAC по ProcessControlPermission, optimistic version,
// audit actor/reason, (ResumeScope не снимает handler_fault_limit; retry — отдельная команда).

beforeEach(fn () => artisan('guard:sync-roles'));

function operatorUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function scopeVersion(string $scopeKey): int
{
    return (int) DB::table('prc_control_scopes')->where('scope_key', $scopeKey)->value('control_version');
}

function blockedByFaultLimit(): WorkId
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

function openIncident(string $seed): ProcessUuid
{
    $id = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(fn () => app(IncidentRepository::class)->open(
        LedgerFixtures::hash($seed), 'acquisition', 'operator_test', ['seed' => $seed], $id, LedgerFixtures::uuid(), LedgerFixtures::uuid(),
    ));

    return $id;
}

test('RBAC: оператор паузит и возобновляет scope, аналитик и пользователь без роли отвергаются без записей', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $commands = app(ProcessOperatorCommands::class);
    $operator = operatorUser(OperatorRole::class);
    $before = scopeVersion('acquisition');

    foreach ([operatorUser(AnalystRole::class), User::factory()->create()] as $denied) {
        expect(fn () => $commands->pauseScope($denied, 'acquisition', $before, 'not allowed'))->toThrow(AuthorizationException::class);
    }
    expect(scopeVersion('acquisition'))->toBe($before)
        ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->count())->toBe(0);

    $paused = $commands->pauseScope($operator, 'acquisition', $before, 'maintenance window');
    expect($paused->status)->toBe(OperatorCommandStatus::Applied)
        ->and($paused->controlVersion)->toBe($before + 1);

    $resumed = $commands->resumeScope($operator, 'acquisition', $before + 1, 'window closed');
    expect($resumed->status)->toBe(OperatorCommandStatus::Applied)
        ->and($resumed->controlVersion)->toBe($before + 2);

    $audit = DB::table('prc_process_transitions')->where('entity_kind', 'control')->orderBy('entity_seq')->get(['previous_state', 'new_state', 'actor_kind', 'actor_id']);
    expect($audit->map(fn (object $row): array => [$row->previous_state, $row->new_state, $row->actor_kind, $row->actor_id])->all())
        ->toBe([['enabled', 'paused', 'operator', $operator->id], ['paused', 'enabled', 'operator', $operator->id]])
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('change_reason'))->toBe('window closed');
});

test('pause/resume: устаревшая версия отвергается, повтор того же режима — Unchanged без новой версии, пустая причина запрещена', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $commands = app(ProcessOperatorCommands::class);
    $operator = operatorUser(OperatorRole::class);
    $version = scopeVersion('acquisition');

    expect($commands->pauseScope($operator, 'acquisition', $version, 'first')->status)->toBe(OperatorCommandStatus::Applied)
        ->and($commands->pauseScope($operator, 'acquisition', $version, 'stale view')->status)->toBe(OperatorCommandStatus::VersionConflict)
        ->and($commands->pauseScope($operator, 'acquisition', $version + 1, 'again')->status)->toBe(OperatorCommandStatus::Unchanged)
        ->and($commands->pauseScope($operator, 'missing-scope', 1, 'nobody home')->status)->toBe(OperatorCommandStatus::NotFound)
        ->and(scopeVersion('acquisition'))->toBe($version + 1)
        ->and(fn () => $commands->resumeScope($operator, 'acquisition', $version + 1, '  '))->toThrow(InvalidArgumentException::class);
});

test('ResumeScope не снимает blocked(handler_fault_limit); retry доступен только суперадмину, повтор commandId — Duplicate', function (): void {
    $work = blockedByFaultLimit();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $commands = app(ProcessOperatorCommands::class);
    $operator = operatorUser(OperatorRole::class);
    $admin = operatorUser(SuperAdminRole::class);
    $seq = ExecutionFixtures::work($work)->transition_seq;
    $command = LedgerFixtures::uuid();

    $version = scopeVersion('acquisition');
    $commands->pauseScope($operator, 'acquisition', $version, 'pause');
    $commands->resumeScope($operator, 'acquisition', $version + 1, 'resume');
    expect(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(fn () => $commands->retryHandlerFaultedWork($operator, $work, $seq, $command, 'operator must not retry'))->toThrow(AuthorizationException::class);

    $retry = $commands->retryHandlerFaultedWork($admin, $work, $seq, $command, 'handler fixed');
    expect($retry->status)->toBe(OperatorCommandStatus::Applied)
        ->and($retry->successor)->not->toBeNull()
        ->and(ExecutionFixtures::work($work)->state)->toBe('superseded');

    $intents = DB::table('prc_operation_intents')->count();
    $again = $commands->retryHandlerFaultedWork($admin, $work, $seq, $command, 'handler fixed');
    expect($again->status)->toBe(OperatorCommandStatus::Duplicate)
        ->and($again->successor?->value->value)->toBe($retry->successor?->value->value)
        ->and(DB::table('prc_operation_intents')->count())->toBe($intents);
});

test('CancelWork через шлюз: version fence, actor и reason в audit, RBAC', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    FixtureProviderRecoveryResolver::install();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $work = ExecutionFixtures::listingWork();
    $commands = app(ProcessOperatorCommands::class);
    $operator = operatorUser(OperatorRole::class);
    $seq = ExecutionFixtures::work($work)->transition_seq;

    expect(fn () => $commands->cancelWork(operatorUser(AnalystRole::class), $work, $seq, 'denied'))->toThrow(AuthorizationException::class)
        ->and($commands->cancelWork($operator, $work, $seq - 1, 'stale')->status)->toBe(OperatorCommandStatus::VersionConflict)
        ->and($commands->cancelWork($operator, $work, $seq, 'operator cancel')->status)->toBe(OperatorCommandStatus::Applied)
        ->and($commands->cancelWork($operator, $work, $seq + 1, 'again')->status)->toBe(OperatorCommandStatus::Terminal);

    $audit = DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'cancelled')->sole();
    expect([$audit->actor_kind, $audit->actor_id, json_decode((string) $audit->details, true)])->toBe(['operator', $operator->id, ['reason' => 'operator cancel']]);
});

test('AcknowledgeIncident: open→acknowledged с audit, повтор — Duplicate, устаревший seq — конфликт, resolved — Terminal', function (): void {
    $commands = app(ProcessOperatorCommands::class);
    $operator = operatorUser(OperatorRole::class);
    $incident = openIncident('ack-1');

    expect(fn () => $commands->acknowledgeIncident(operatorUser(AnalystRole::class), $incident, 1, 'denied'))->toThrow(AuthorizationException::class)
        ->and($commands->acknowledgeIncident($operator, $incident, 2, 'stale')->status)->toBe(OperatorCommandStatus::VersionConflict)
        ->and($commands->acknowledgeIncident($operator, $incident, 1, 'looking into it')->status)->toBe(OperatorCommandStatus::Applied)
        ->and($commands->acknowledgeIncident($operator, $incident, 2, 'twice')->status)->toBe(OperatorCommandStatus::Duplicate)
        ->and($commands->acknowledgeIncident($operator, LedgerFixtures::uuid(), 1, 'missing')->status)->toBe(OperatorCommandStatus::NotFound);

    $row = DB::table('prc_process_incidents')->where('id', $incident->value)->sole();
    $audit = DB::table('prc_process_transitions')->where('incident_id', $incident->value)->where('new_state', 'acknowledged')->sole();
    expect([$row->state, $row->acknowledged_by, $row->transition_seq])->toBe(['acknowledged', $operator->id, 2])
        ->and([$audit->previous_state, $audit->actor_kind, $audit->actor_id, json_decode((string) $audit->details, true)])
        ->toBe(['open', 'operator', $operator->id, ['reason' => 'looking into it']]);

    $resolved = openIncident('ack-2');
    DB::update("UPDATE prc_process_incidents SET state = 'resolved', resolved_at = clock_timestamp() WHERE id = ?", [$resolved->value]);
    expect($commands->acknowledgeIncident($operator, $resolved, 1, 'too late')->status)->toBe(OperatorCommandStatus::Terminal);
});
