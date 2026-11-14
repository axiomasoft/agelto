<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\StageCode;
use App\Models\User;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures as F;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;

function suspendedProcessWork(int $failures = 0): WorkId
{
    F::authority([StageCode::Listing]);
    $work = E::listingWork();
    $lease = F::running($work);
    $saved = app(WorkStoreRepository::class)
        ->checkpoint($lease, new FixtureStageCheckpoint(501), 0);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['item_failures' => $failures]);
    app(WorkFinalizer::class)->finish($lease->checkpointed($saved),
        new StageDeferred(DeferralReason::RateLimit, new RetryAt(new DateTimeImmutable('+1 hour'))));

    return $work;
}

test('Process feeder emits one durable wake when a suspended work becomes due and retains progress', function (): void {
    $work = suspendedProcessWork();
    $before = E::work($work);
    $wakes = DB::table('prc_process_outbox')->where('kind', 'work_wake')->count();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe($wakes + 1)
        ->and(E::work($work)->checkpoint)->toBe($before->checkpoint)
        ->and(E::work($work)->item_failures)->toBe(0)
        ->and(Schema::hasTable('prc_source_incidents'))->toBeFalse()
        ->and(Schema::hasTable('prc_source_circuits'))->toBeFalse();
});

test('automatic Process retry waits for RetryAt and cannot clear an operator pause', function (): void {
    $work = suspendedProcessWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
    E::setMode('acquisition', ControlMode::Paused);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(0)
        ->and(E::work($work)->state)->toBe('deferred')
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole()->desired_mode)->toBe('paused');
});

test('operator resume preserves the network wait and item budget rather than restarting a legacy run', function (): void {
    $work = suspendedProcessWork(2);
    E::setMode('acquisition', ControlMode::Paused);
    $before = E::work($work);
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $actor = User::factory()->create();
    $actor->assignRole('operator');
    $version = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole()->control_version;
    app(ProcessOperatorCommands::class)->resumeScope($actor, 'acquisition', $version, 'Проверен сохранённый вход');
    expect(E::work($work)->due_at)->toBe($before->due_at)->and(E::work($work)->item_failures)->toBe(2)
        ->and(E::work($work)->checkpoint)->toBe($before->checkpoint)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->where('actor_id', $actor->id)->count())->toBe(1);
});

test('delta and recovery share the same durable deferral mechanism without legacy resumer commands', function (): void {
    $work = suspendedProcessWork();
    expect(array_key_exists('crawler:recover-stale', Artisan::all()))->toBeFalse()
        ->and(array_key_exists('crawler:rescan-backlog', Artisan::all()))->toBeFalse()
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(E::work($work)->state)->toBe('deferred')
        ->and(E::work($work)->checkpoint)->not->toBeNull();
});

test('expired target cooldown does not clear a manual pause or rewrite health from the work resumer', function (): void {
    $work = suspendedProcessWork();
    NetworkFixtures::platform('suspended');
    app(SourceCatalogSync::class)->sync(NetworkFixtures::catalog('suspended'));
    HistoricalNetworkFixtures::synchronize();
    $targets = DB::table('prc_source_targets')->where('capability', 'detail')->pluck('id');
    DB::table('prc_target_health')->whereIn('target_id', $targets)->update(['state' => 'open', 'open_until' => now()->subMinute()]);
    E::setMode('acquisition', ControlMode::Paused);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
    expect(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(0)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(DB::table('prc_target_health')->whereIn('target_id', $targets)->pluck('state')->all())->toBe(['open'])
        ->and(E::work($work)->state)->toBe('deferred');
});

test('absolute network RetryAt survives pause and repeated scans without an item failure', function (): void {
    $start = new DateTimeImmutable('+1 hour');
    $work = suspendedProcessWork(2);
    $before = E::work($work);
    expect(new DateTimeImmutable($before->due_at))->toBeGreaterThanOrEqual($start);
    E::setMode('acquisition', ControlMode::Paused);
    foreach (range(1, 2) as $unused) {
        expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    }
    expect(E::work($work)->due_at)->toBe($before->due_at)->and(E::work($work)->item_failures)->toBe(2)
        ->and(E::work($work)->owner_token)->toBeNull()->and(E::work($work)->checkpoint)->toBe($before->checkpoint);
});
