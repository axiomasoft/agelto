<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DaemonHealth;
use App\Enums\Procurement\Process\DaemonKind;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\DaemonHeartbeatRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\DaemonLoop;
use App\Services\Procurement\Process\DaemonPolicy;
use App\Services\Procurement\Process\DaemonReadiness;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('claim-recovery-and-invariants', 'work-controls-and-fences', 'daemon-reconciliation');

// Настоящий RecoveryReconciler на реальном ledger: expired running/admitted и release blocked.
// Ход времени моделируется raw UPDATE lease/deadline (факты), а не подменой recovery fixture.

function recoveryWork(WorkId $work): object
{
    return DB::selectOne(
        'SELECT state, reason_code, generation, owner_token, lease_until, dispatch_generation, dispatch_claim_until, checkpoint::text AS checkpoint,
                checkpoint_seq, infrastructure_interruptions, transition_seq, item_failures
         FROM prc_process_work WHERE id = ?',
        [$work->value->value],
    );
}

function recoveryGroups(WorkId $work): array
{
    return DB::table('prc_admission_groups')->where('work_id', $work->value->value)->orderBy('admitted_at')->get(['id', 'state', 'version', 'dispatch_generation', 'release_reason'])->all();
}

function recoveryLastTransition(WorkId $work): object
{
    return DB::table('prc_process_transitions')->where('work_id', $work->value->value)->orderByDesc('entity_seq')
        ->first(['entity_seq', 'previous_state', 'new_state', 'reason_code', 'actor_kind']);
}

/** Admitted listing work: реальный feeder tick выдаёт группу и work_wake. */
function recoveryAdmitted(): WorkId
{
    // Authority и finalization binding привязываются один раз на PHP процесс.
    if (app(StageRegistry::class)->handlerVersion(StageCode::Listing) === null) {
        FinalizeFixtures::authority([StageCode::Listing]);
    }
    $work = ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);

    return $work;
}

test('expired running после простоя больше 5 минут: ready с durable checkpoint, generation+1, attempt interrupted, вся group released; поздний finalize старого владельца отвергнут', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    expect(app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(7), 0)->status->value)->toBe('saved');
    FinalizeFixtures::expire($work, 600);
    $before = recoveryWork($work);

    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);

    $row = recoveryWork($work);
    $group = recoveryGroups($work)[0];
    $attempt = DB::table('prc_process_attempts')->where('work_id', $work->value->value)->sole();
    expect([$row->state, $row->reason_code, $row->generation, $row->owner_token, $row->lease_until])->toBe(['ready', null, $before->generation + 1, null, null])
        ->and($row->infrastructure_interruptions)->toBe(1)
        ->and([$row->checkpoint_seq, $row->item_failures])->toBe([1, 0])
        ->and(json_decode($row->checkpoint, true)['stage_progress']['payload'])->toBe(['position' => 7])
        ->and(json_decode($row->checkpoint, true)['provider_recovery_plan'])->toBe(['variant' => 'none'])
        ->and([$group->state, $group->release_reason])->toBe(['released', 'lease_recovery'])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and([$attempt->terminal_kind, $attempt->finished_at !== null])->toBe(['interrupted', true])
        ->and(recoveryLastTransition($work))->toMatchObject(['previous_state' => 'running', 'new_state' => 'ready', 'reason_code' => 'lease_recovery', 'actor_kind' => 'reconciler'])
        ->and(DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->count())->toBeGreaterThanOrEqual(1);

    // Старый владелец: ноль domain writes и ноль successor/slot release.
    $writes = $results->resultWrites;
    expect(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'late'))->status)->toBe(CompletionStatus::LostOwnership)
        ->and($results->resultWrites)->toBe($writes)
        ->and(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0);
});

test('живой lease и expired-but-finalized work recovery не трогает: finalize до takeover остаётся accepted', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $live = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($live);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0)
        ->and(recoveryWork($live)->state)->toBe('running');

    $late = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($late);
    FinalizeFixtures::expire($late);
    expect(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'before-takeover'))->status)->toBe(CompletionStatus::Applied)
        ->and(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0)
        ->and([recoveryWork($late)->state, recoveryWork($late)->generation])->toBe(['succeeded', 1]);
});

test('истёкший lease не оживляется heartbeat; после recovery heartbeat и checkpoint старого владельца — Lost', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $store = app(WorkStoreRepository::class);
    expect($store->heartbeat($lease)->isRenewed())->toBeTrue();

    FinalizeFixtures::expire($work, 600);
    expect($store->heartbeat($lease)->isRenewed())->toBeFalse()
        ->and(recoveryWork($work)->state)->toBe('running')
        ->and(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and($store->heartbeat($lease)->isRenewed())->toBeFalse()
        ->and($store->checkpoint($lease, new FixtureStageCheckpoint(1), 0)->status->value)->toBe('lost_ownership')
        ->and(recoveryWork($work)->checkpoint_seq)->toBe(0);
});

test('recovery при сменившемся input: fenced superseded без публикации, attempt superseded, group released', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1 WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$work->value->value]);
    FinalizeFixtures::expire($work, 600);

    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);

    $row = recoveryWork($work);
    expect([$row->state, $row->reason_code, $row->owner_token, $row->checkpoint])->toBe(['superseded', 'input_invalidated', null, null])
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->value('terminal_kind'))->toBe('superseded')
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and($results->resultWrites)->toBe(0);
});

test('recovery под pause и held handler: blocked с typed reason; release только когда ВСЕ scopes enabled и handler healthy', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    FinalizeFixtures::expire($work, 600);
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);

    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $row = recoveryWork($work);
    expect([$row->state, $row->reason_code, $row->owner_token])->toBe(['blocked', 'paused', null])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);

    // Pause сохраняется: release-кандидатов нет, work остаётся blocked без tick-eligibility.
    expect(app(RecoveryReconciler::class)->releaseBlocked())->toBe(0)
        ->and(recoveryWork($work)->state)->toBe('blocked');

    ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    ExecutionFixtures::holdHandler(StageCode::Listing);
    // Held handler: scopes enabled, но executing version held — work остаётся blocked.
    expect(app(RecoveryReconciler::class)->releaseBlocked())->toBe(0)
        ->and(recoveryWork($work)->state)->toBe('blocked');

    DB::update("UPDATE prc_process_handler_holds SET state = 'healthy' WHERE stage = 'listing'");
    expect(app(RecoveryReconciler::class)->releaseBlocked())->toBe(1);
    $released = recoveryWork($work);
    expect([$released->state, $released->reason_code])->toBe(['ready', null])
        ->and(recoveryLastTransition($work))->toMatchObject(['previous_state' => 'blocked', 'new_state' => 'ready', 'reason_code' => 'control_released']);

    // Held handler при самом recovery: typed handler_hold, а не ready.
    $held = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($held);
    FinalizeFixtures::expire($held, 600);
    ExecutionFixtures::holdHandler(StageCode::Listing);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(recoveryWork($held)->reason_code)->toBe('handler_hold');
});

test('expired admitted: потерянная delivery получает новый dispatch generation с той же group и обычным wake; старая доставка stale, новая claim-ится', function (): void {
    $work = recoveryAdmitted();
    $group = recoveryGroups($work)[0];
    // Relay отметил wake published, Redis его потерял; delivery deadline давно истёк (downtime > 5 мин).
    DB::update("UPDATE prc_process_outbox SET published_at = clock_timestamp() WHERE kind = 'work_wake'");
    DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '10 minutes' WHERE id = ?", [$work->value->value]);
    $stale = ExecutionFixtures::delivery($work);

    expect(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(1);

    $row = recoveryWork($work);
    $after = recoveryGroups($work);
    expect([$row->state, $row->dispatch_generation])->toBe(['admitted', $stale->value + 1])
        ->and($row->dispatch_claim_until)->not->toBeNull()
        ->and($after)->toHaveCount(1)
        ->and([$after[0]->id, $after[0]->state, $after[0]->dispatch_generation, $after[0]->version])->toBe([$group->id, 'admitted', $stale->value + 1, $group->version + 1])
        ->and(DB::table('prc_process_reservations')->where('work_id', $work->value->value)->count())->toBe(2)
        ->and(DB::table('prc_process_attempts')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'wake:'.$work->value->value.':'.($stale->value + 1))->whereNull('published_at')->count())->toBe(1)
        // Новая delivery ещё жива: повторный tick её не переиздаёт.
        ->and(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(0);

    $store = app(WorkStoreRepository::class);
    expect($store->claim($work, $stale)->status)->toBe(ClaimStatus::StaleDelivery)
        ->and($store->claim($work, ExecutionFixtures::delivery($work))->status)->toBe(ClaimStatus::Claimed);
});

test('admitted под committed pause: blocked(paused), вся group released без item budget; stale input — superseded', function (): void {
    $work = recoveryAdmitted();
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);

    expect(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(1);

    $row = recoveryWork($work);
    expect([$row->state, $row->reason_code, $row->dispatch_claim_until, $row->item_failures])->toBe(['blocked', 'paused', null, 0])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(recoveryGroups($work)[0]->state)->toBe('released');

    ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    $stale = recoveryAdmitted();
    DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1 WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$stale->value->value]);
    DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '1 minute' WHERE id = ?", [$stale->value->value]);

    expect(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(1)
        ->and(recoveryWork($stale)->state)->toBe('superseded')
        ->and(DB::table('prc_process_reservations')->where('work_id', $stale->value->value)->count())->toBe(0);
});

test('ошибка схемы не останавливает соседний recovery, daemon остаётся degraded до исправления work', function (bool $blocked): void {
    $poison = recoveryAdmitted();
    $healthy = recoveryAdmitted();
    $reconciler = app(RecoveryReconciler::class);
    if ($blocked) {
        ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
        expect($reconciler->recoverAdmitted())->toBe(2);
        ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    } else {
        DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '1 minute' WHERE state = 'admitted'");
    }
    // A rolling deployment may encounter an input schema it cannot decode. Preserve that work
    // and keep processing the rest of the bounded batch.
    DB::table('prc_process_work')->where('id', $poison->value->value)->update(['input_schema_version' => 2]);

    expect($blocked ? $reconciler->releaseBlocked() : $reconciler->recoverAdmitted())->toBe(1)
        ->and(recoveryWork($poison)->state)->toBe($blocked ? 'blocked' : 'admitted')
        ->and(recoveryWork($healthy)->state)->toBe($blocked ? 'ready' : 'admitted')
        ->and(recoveryWork($healthy)->dispatch_generation)->toBe($blocked ? 1 : 2);

    $daemon = new ReconcileDaemon(app(DaemonPolicy::class));
    foreach ($reconciler->categories() as $category) {
        $daemon->register($category);
    }
    $clock = Mockery::mock(ProcessClock::class);
    $clock->shouldReceive('monotonicNs')->andReturn(0);
    $clock->shouldReceive('sleep')->andReturnNull();
    $instance = LedgerFixtures::uuid();
    $health = [];
    $errors = [];
    $polls = 0;
    $loop = new DaemonLoop($clock, app(DaemonHeartbeatRepository::class), app(DaemonPolicy::class), static fn (): int => 0);

    $reason = $loop->run($daemon, $instance, function () use ($poison, $instance, &$health, &$errors, &$polls): bool {
        if (count($health) === 2) {
            return true; // DaemonLoop may poll the same stop predicate again before returning.
        }
        if (++$polls > 80) {
            return true; // A missing error signal must fail assertions instead of hanging the loop.
        }
        $error = DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)->value('last_error_code');
        if ($health === [] && $error !== null) {
            $health[] = app(DaemonReadiness::class)->of(DaemonKind::Reconcile);
            $errors[] = $error;
            DB::table('prc_process_work')->where('id', $poison->value->value)->update(['input_schema_version' => 1]);
        } elseif ($health !== [] && $error === null) {
            $health[] = app(DaemonReadiness::class)->of(DaemonKind::Reconcile);
            $errors[] = $error;

            return true;
        }

        return false;
    });

    expect($reason)->toBe(DaemonLoop::STOP_SIGNAL)
        ->and($health)->toBe([DaemonHealth::Degraded, DaemonHealth::Healthy])
        ->and($errors)->toBe(['reconcile_category_failed', null])
        ->and(recoveryWork($poison)->state)->toBe($blocked ? 'ready' : 'admitted')
        ->and(DB::table('prc_process_daemon_heartbeats')->where('instance_id', $instance->value)->exists())->toBeFalse();
})->with(['admitted redelivery' => [false], 'blocked release' => [true]]);
