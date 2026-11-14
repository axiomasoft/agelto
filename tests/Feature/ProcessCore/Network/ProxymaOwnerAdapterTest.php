<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\ScopeKind;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\ProcessOwnerAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Proxyma\Contracts\OwnerAuthority;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\LockedOwnerState;
use Proxyma\Domain\Execution\OwnerRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures as P;

beforeEach(fn () => P::clean());
afterEach(fn () => P::clean());

test('fixture reset пересоздаёт package scope после замены test transaction manager', function (): void {
    $oldScope = app(ConnectionScope::class);
    $oldOwner = app(ProcessOwnerAuthority::class);
    $manager = new DatabaseTransactionsManager([]);
    app()->instance('db.transactions', $manager);

    P::clean();

    expect(app(ConnectionScope::class))->not->toBe($oldScope)
        ->and(app(ProcessOwnerAuthority::class))->not->toBe($oldOwner);
    expect(P::locked(function ($transaction): bool {
        app(ConnectionScope::class)->assertActive($transaction);

        return true;
    }))->toBeTrue();
});

test('неактивный package owner не выдаёт authority при legacy selector', function (): void {
    $f = new F;
    $authority = app(ProcessOwnerAuthority::class);
    $ref = $authority->reference($f->context()->lease());
    $snapshot = $authority->snapshot($ref);
    expect(app(OwnerAuthority::class))->toBe($authority)
        ->and(P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx)))->toBeInstanceOf(ExecutionBlocked::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('standalone owner проверяет token generation controls deadline и selector generation', function (string $change): void {
    $f = new F;
    P::activate();
    $authority = app(ProcessOwnerAuthority::class);
    $handle = $f->context()->lease();
    $ref = $authority->reference($handle);
    $snapshot = $authority->snapshot($ref);
    match ($change) {
        'token' => DB::table('prc_outbound_operations')->where('id', $handle->id->value)->update(['owner_token' => LedgerFixtures::uuid()->value]),
        'generation' => DB::table('prc_outbound_operations')->where('id', $handle->id->value)->increment('generation'),
        'controls' => DB::table('prc_control_scopes')->where('scope_key', 'system')->update(['desired_mode' => 'paused']),
        'control_version' => DB::table('prc_control_scopes')->where('scope_key', 'system')->increment('control_version'),
        'deadline' => DB::table('prc_outbound_operations')->where('id', $handle->id->value)->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]),
        'selector' => DB::table('prc_network_authorities')->increment('generation'),
        'none' => null,
    };
    $result = P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx));
    expect($result)->toBeInstanceOf($change === 'none' ? LockedOwnerState::class : ExecutionBlocked::class);
})->with(['none', 'token', 'generation', 'controls', 'control_version', 'deadline', 'selector']);

test('Process owner сохраняет input membership handler epoch и поколение', function (string $change): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    if ($change === 'request_hash' || $change === 'input_revision') {
        $lease = new ExecutionLease(...array_replace(get_object_vars($lease), $change === 'request_hash'
            ? ['inputHash' => LedgerFixtures::hash('different-request')]
            : ['inputRevision' => $lease->inputRevision + 1]));
    }
    P::activate();
    $authority = app(ProcessOwnerAuthority::class);
    $ref = $authority->reference($lease);
    $snapshot = $authority->snapshot($ref);
    match ($change) {
        'handler' => ExecutionFixtures::holdHandler(StageCode::Listing),
        'input' => DB::table('prc_crawl_blocks')->where('id', ExecutionFixtures::work($work)->crawl_block_id)->increment('head_generation'),
        'membership' => DB::table('prc_process_reservations')->where('work_id', $work->value->value)->delete(),
        'generation' => DB::table('prc_process_work')->where('id', $work->value->value)->increment('generation'),
        'token' => DB::table('prc_process_work')->where('id', $work->value->value)->update(['owner_token' => LedgerFixtures::uuid()->value]),
        'lease' => FinalizeFixtures::expire($work),
        'request_hash', 'input_revision' => null,
        'none' => null,
    };
    expect(P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx)))
        ->toBeInstanceOf($change === 'none' ? LockedOwnerState::class : ExecutionBlocked::class);
})->with(['none', 'handler', 'input', 'membership', 'generation', 'token', 'lease', 'request_hash', 'input_revision']);

test('owner network prefix разделяет read lane, но сохраняет writer и mutation fences', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    P::activate();
    $authority = app(ProcessOwnerAuthority::class);
    $owner = $authority->reference($lease);
    $snapshot = $authority->snapshot($owner);
    $lane = DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->soleValue('id');
    $name = 'owner_lock_'.bin2hex(random_bytes(6));
    config(["database.connections.{$name}" => config('database.connections.pgsql')]);
    $other = DB::connection($name);

    try {
        $main = DB::selectOne('SELECT current_database() AS database, pg_backend_pid() AS pid');
        $peer = $other->selectOne('SELECT current_database() AS database, pg_backend_pid() AS pid');
        expect($main->database)->toBe('agelto_test')
            ->and($peer->database)->toBe('agelto_test')
            ->and($peer->pid)->not->toBe($main->pid)
            ->and($other->transactionLevel())->toBe(0);

        $blockedWriter = null;
        $state = P::locked(function ($tx) use ($authority, $owner, $snapshot, $other, $lane, &$blockedWriter): LockedOwnerState|ExecutionBlocked {
            $state = $authority->lockAndValidate($owner, $snapshot, $tx);
            $other->transaction(static function () use ($other, $lane): void {
                $other->select('SELECT id FROM prc_process_lanes WHERE id = ? FOR SHARE NOWAIT', [$lane]);
            });
            try {
                $other->transaction(static function () use ($other, $lane): void {
                    $other->statement("SET LOCAL lock_timeout = '100ms'");
                    $other->update('UPDATE prc_process_lanes SET max_running = max_running WHERE id = ?', [$lane]);
                });
            } catch (QueryException $exception) {
                $blockedWriter = $exception;
            }

            return $state;
        });
        expect($state)->toBeInstanceOf(LockedOwnerState::class)
            ->and($blockedWriter)->toBeInstanceOf(QueryException::class)
            ->and($blockedWriter->errorInfo[0] ?? null)->toBe('55P03');

        $mutationSnapshot = app(AuthorityRepository::class)->readAuthoritySnapshot($work);
        if ($mutationSnapshot instanceof AuthorityGap) {
            throw new RuntimeException('Expected mutation authority snapshot.');
        }
        $blockedReader = null;
        DB::transaction(function () use ($mutationSnapshot, $other, $lane, &$blockedReader): void {
            app(AuthorityRepository::class)->lockPrefix($mutationSnapshot, false);
            try {
                $other->transaction(static function () use ($other, $lane): void {
                    $other->select('SELECT id FROM prc_process_lanes WHERE id = ? FOR SHARE NOWAIT', [$lane]);
                });
            } catch (QueryException $exception) {
                $blockedReader = $exception;
            }
        });
        expect($blockedReader)->toBeInstanceOf(QueryException::class)
            ->and($blockedReader->errorInfo[0] ?? null)->toBe('55P03');
    } finally {
        DB::purge($name);
        config(["database.connections.{$name}" => null]);
    }
});

test('Process owner отвергает завершённый intent связанной detail work', function (): void {
    FinalizeFixtures::authority([StageCode::Detail]);
    $procurement = Procurement::factory()->create();
    $intent = LedgerFixtures::uuid();
    $work = app(ProcessTransaction::class)->run(function () use ($procurement, $intent): WorkId {
        app(WorkStoreRepository::class)->storeIntent(
            id: $intent,
            intentKey: LedgerFixtures::hash('intent:'.$intent->value),
            stage: StageCode::Detail,
            subject: new SubjectKey(SubjectKind::Procurement, $procurement->id),
            semanticPolicyHash: LedgerFixtures::hash('semantic'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );

        return LedgerFixtures::storeWork(LedgerFixtures::detailInput($procurement->id, $intent), intentId: $intent);
    });
    $lease = FinalizeFixtures::running($work);
    P::activate();
    $authority = app(ProcessOwnerAuthority::class);
    $ref = $authority->reference($lease);
    $snapshot = $authority->snapshot($ref);
    expect(ExecutionFixtures::work($work)->intent_id)->toBe($intent->value)
        ->and(P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx)))->toBeInstanceOf(LockedOwnerState::class);

    expect(DB::table('prc_operation_intents')->where('id', $intent->value)->update(['state' => 'terminal']))->toBe(1);

    expect(P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx)))->toBeInstanceOf(ExecutionBlocked::class);
});

test('parent attempt standalone probe требует живой полный prefix и immutable control closure', function (string $change): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $f = new F;
    $handle = $f->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid())->lease();
    DB::table('prc_outbound_operations')->where('id', $handle->id->value)->update(['parent_attempt_id' => $lease->attemptId->value]);
    $native = app(NetworkOwnerFence::class)->snapshot($lease);
    foreach ($native->controls as $control) {
        DB::table('prc_outbound_operation_controls')->insertOrIgnore(['operation_id' => $handle->id->value, 'control_scope_id' => $control->id->value, 'control_version' => $control->version]);
    }
    P::activate();
    $authority = app(ProcessOwnerAuthority::class);
    $ref = $authority->reference($handle);
    $snapshot = $authority->snapshot($ref);
    expect($snapshot->parentAttemptId->value)->toBe($lease->attemptId->value);
    match ($change) {
        'parent' => DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->update(['finished_at' => DB::raw('clock_timestamp()')]),
        'closure' => DB::table('prc_outbound_operation_controls')->where('operation_id', $handle->id->value)->where('control_scope_id', $native->controls[array_key_last($native->controls)]->id->value)->delete(),
        'none' => null,
    };
    expect(P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx)))
        ->toBeInstanceOf($change === 'none' ? LockedOwnerState::class : ExecutionBlocked::class);
})->with(['none', 'parent', 'closure']);

test('owner snapshot отвергает чужой tenant реконструкцию и provider вне AI stages', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $lease = FinalizeFixtures::running(ExecutionFixtures::listingWork());
    $authority = app(ProcessOwnerAuthority::class);
    $ref = $authority->reference($lease);
    $snapshot = $authority->snapshot($ref);
    $foreign = new OwnerRef(new TenantKey('foreign'), $ref->ownerType, $ref->ownerId, $ref->token(), $ref->generation);
    expect($authority->validateSnapshot($foreign, $snapshot))->toBeFalse()
        ->and($authority->snapshot($foreign))->toBeNull()
        ->and($authority->snapshot($authority->reference($lease, new ProviderKey('fixture'))))->toBeNull();
});

test('provider owner требует control провайдера перед network prefix', function (bool $paused): void {
    $period = FinancialFixtures::reset();
    [, $lease] = FinancialFixtures::authorized($period);
    app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)->ensureScopes([
        new ScopeDefinition(ScopeKind::System, 'system', null),
        new ScopeDefinition(ScopeKind::Provider, 'provider:fixture-provider', 'system'),
    ]));
    P::activate();
    $authority = app(ProcessOwnerAuthority::class);
    $ref = $authority->reference($lease, new ProviderKey('fixture-provider'));
    $snapshot = $authority->snapshot($ref);
    expect($snapshot->controlVersions)->toHaveKey('provider:fixture-provider');
    if ($paused) {
        DB::table('prc_control_scopes')->where('scope_key', 'provider:fixture-provider')->update(['desired_mode' => 'paused']);
    }
    expect(P::locked(fn ($tx) => $authority->lockAndValidate($ref, $snapshot, $tx)))
        ->toBeInstanceOf($paused ? ExecutionBlocked::class : LockedOwnerState::class);
})->with([false, true]);
