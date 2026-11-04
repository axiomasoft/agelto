<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\InvariantViolation;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\InvariantKind;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\InvariantRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\InvariantScan;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\SystemProcessClock;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('claim-recovery-and-invariants');

// This scanner must commit its read transaction before mutation. Release RefreshDatabase's
// empty outer transaction and use the same explicit isolated-ledger cleanup as concurrency tests.
beforeEach(function (): void {
    DB::commit();
    ExecutionFixtures::truncateLedger();
    FinalizeFixtures::authority([StageCode::Listing]);
});
afterEach(fn () => ExecutionFixtures::truncateLedger());

final class InvariantScanClock implements ProcessClock
{
    public int $seconds = 0;

    public function dbNow(): DbInstant
    {
        return app(SystemProcessClock::class)->dbNow();
    }

    public function monotonicNs(): int
    {
        return $this->seconds * 1_000_000_000;
    }

    public function sleep(Duration $duration): void
    {
        throw new LogicException('Invariant scan must not sleep.');
    }
}

function invariantCorruptHash(WorkId $work): void
{
    DB::update("UPDATE prc_admission_groups SET required_lane_set_hash = decode(?, 'hex') WHERE work_id = ? AND state <> 'released'", [LedgerFixtures::hash('corrupt')->hex, $work->value->value]);
}

function invariantWorkSnapshot(WorkId $work): string
{
    return (string) DB::table('prc_process_work as w')
        ->selectRaw('to_jsonb(w)::text AS snapshot')
        ->where('w.id', $work->value->value)
        ->sole()->snapshot;
}

test('invariant healthy: точные ready/admitted/running/released/deferred/blocked ledgers не дают incident, hold или processed rows', function (): void {
    $done = ExecutionFixtures::listingWork();
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($done), FinalizeFixtures::succeeded(StageCode::Listing, 'invariant-healthy'));
    $deferred = ExecutionFixtures::listingWork();
    app(WorkFinalizer::class)->finishHandlerFault(FinalizeFixtures::running($deferred), FinalizeFixtures::fault('healthy-history'));
    $blocked = ExecutionFixtures::listingWork();
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($blocked), new StageBlocked(BlockedReason::Paused));
    $running = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($running);
    $admitted = ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);
    ExecutionFixtures::listingWork(); // ready, never delivered
    $before = DB::table('prc_process_transitions')->count();

    expect(app(InvariantScan::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_process_incidents')->count())->toBe(0)
        ->and(DB::table('prc_control_scopes')->where('desired_mode', 'paused')->count())->toBe(0)
        ->and(DB::table('prc_process_transitions')->count())->toBe($before)
        ->and(ExecutionFixtures::work($admitted)->state)->toBe('admitted')
        ->and(ExecutionFixtures::work($running)->state)->toBe('running');
});

test('invariant cadence: зарегистрирован один шестой scan, настоящий интервал 60s; прежние категории продолжают tick5s', function (): void {
    $clock = new InvariantScanClock;
    app()->instance(ProcessClock::class, $clock);
    $daemon = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $daemon->register($category);
    }
    expect($daemon->categories())->toBe(['document_storage_cleanup', 'outbound_expired_owners', 'outbound_probes', 'expired_running', 'admitted', 'blocked_release', 'blocked_provider', 'handler_fault_sweep', 'invariant']);
    $scans = 0;
    DB::listen(function (QueryExecuted $query) use (&$scans): void {
        if (str_contains($query->sql, 'WITH required(')) {
            $scans++;
        }
    });
    expect([$daemon->tick()->processed, $scans])->toBe([0, 1]);
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    invariantCorruptHash($work);
    // A different expired work is still recovered at 5s, before the invariant cadence is due.
    $expired = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($expired);
    FinalizeFixtures::expire($expired);
    $clock->seconds = 5;
    $tick = $daemon->tick();
    expect([$tick->processed, $tick->errorCode, $scans])->toBe([1, null, 1]);
    foreach ([10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 59] as $seconds) {
        $clock->seconds = $seconds;
        expect([$daemon->tick()->processed, $scans])->toBe([0, 1]);
    }
    expect(DB::table('prc_process_incidents')->count())->toBe(0);
    $clock->seconds = 60;
    $tick = $daemon->tick();
    expect([$tick->processed, $tick->errorCode, $scans])->toBe([1, null, 2])
        ->and(DB::table('prc_process_incidents')->where('kind', InvariantKind::GroupMembership->value)->count())->toBe(1);
});

test('invariant negative predicates: corruption обнаружена без queue delivery, exact incident durable и group не исправлена молча', function (string $mutation, InvariantKind $kind): void {
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    $other = ExecutionFixtures::listingWork();
    match ($mutation) {
        'missing' => DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ? AND lane_id = (SELECT id FROM prc_process_lanes WHERE lane_key = ?)', [$group->id, 'acquisition.listing']),
        'extra' => DB::insert("INSERT INTO prc_process_reservations (admission_group_id, lane_id, work_id, stage, state) SELECT ?, id, ?, 'listing', 'running' FROM prc_process_lanes WHERE lane_key = 'acquisition.detail'", [$group->id, $work->value->value]),
        'hash' => invariantCorruptHash($work),
        'member_work' => DB::update('UPDATE prc_process_reservations SET work_id = ? WHERE admission_group_id = ?', [$other->value->value, $group->id]),
        'member_stage' => DB::update("UPDATE prc_process_reservations SET stage = 'detail' WHERE admission_group_id = ?", [$group->id]),
        'member_state' => DB::update("UPDATE prc_process_reservations SET state = 'admitted' WHERE admission_group_id = ?", [$group->id]),
        'dispatch' => DB::update('UPDATE prc_admission_groups SET dispatch_generation = dispatch_generation + 1 WHERE id = ?', [$group->id]),
        'group_state' => DB::update("UPDATE prc_admission_groups SET state = 'admitted' WHERE id = ?", [$group->id]),
        'released' => DB::update("UPDATE prc_admission_groups SET state = 'released', released_at = clock_timestamp() WHERE id = ?", [$group->id]),
        'missing_group' => DB::transaction(function () use ($group): void {
            DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ?', [$group->id]);
            DB::delete('DELETE FROM prc_admission_groups WHERE id = ?', [$group->id]);
        }),
        'attempt_missing' => DB::update('UPDATE prc_admission_groups SET attempt_id = NULL WHERE id = ?', [$group->id]),
        'attempt_work' => DB::update('UPDATE prc_process_attempts SET work_id = ? WHERE id = ?', [$other->value->value, $group->attempt_id]),
        'attempt_generation' => DB::update('UPDATE prc_process_attempts SET generation = generation + 1 WHERE id = ?', [$group->attempt_id]),
        'attempt_token' => DB::update("UPDATE prc_process_attempts SET owner_token_fingerprint = decode(?, 'hex') WHERE id = ?", [LedgerFixtures::hash('wrong-token')->hex, $group->attempt_id]),
        'attempt_finished' => DB::update("UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = 'succeeded' WHERE id = ?", [$group->attempt_id]),
        'attempt_half_finished' => DB::update("UPDATE prc_process_attempts SET terminal_kind = 'succeeded' WHERE id = ?", [$group->attempt_id]),
    };
    $members = DB::table('prc_process_reservations')->orderBy('admission_group_id')->orderBy('lane_id')->get()->toJson();
    $workBefore = invariantWorkSnapshot($work);
    $laneBefore = DB::table('prc_process_lanes')->orderBy('id')->get()->toJson();
    $wakes = DB::table('prc_process_outbox')->where('kind', 'work_wake')->count();
    expect(app(InvariantScan::class)->reconcile())->toBeGreaterThan(0);
    $scope = $kind === InvariantKind::ReleasedMembers ? 'group:'.$group->id : 'work:'.$work->value->value;
    $incident = DB::table('prc_process_incidents')->where('kind', $kind->value)->where('scope_key', $scope)->sole();
    expect([$incident->state, $incident->occurrences, $incident->transition_seq])->toBe(['open', 1, 1])
        ->and(json_decode($incident->evidence, true))->toMatchArray(['predicate' => $kind->value, 'policy_version' => 1])
        ->and(DB::table('prc_process_transitions')->where('incident_id', $incident->id)->count())->toBe(1)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->value('desired_mode'))->toBe('paused')
        ->and(invariantWorkSnapshot($work))->toBe($workBefore)
        ->and(DB::table('prc_process_reservations')->orderBy('admission_group_id')->orderBy('lane_id')->get()->toJson())->toBe($members)
        ->and(DB::table('prc_process_lanes')->orderBy('id')->get()->toJson())->toBe($laneBefore)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe($wakes)
        ->and(app(InvariantScan::class)->reconcile())->toBe(0); // restart/new instance, durable dedup
})->with([
    'missing member' => ['missing', InvariantKind::GroupMembership],
    'extra member' => ['extra', InvariantKind::GroupMembership],
    'wrong hash' => ['hash', InvariantKind::GroupMembership],
    'foreign member work' => ['member_work', InvariantKind::GroupMembership],
    'foreign member stage' => ['member_stage', InvariantKind::GroupMembership],
    'wrong member state' => ['member_state', InvariantKind::GroupMembership],
    'wrong dispatch' => ['dispatch', InvariantKind::DispatchGeneration],
    'group and work disagree' => ['group_state', InvariantKind::WorkState],
    'released active members' => ['released', InvariantKind::ReleasedMembers],
    'active work without group' => ['missing_group', InvariantKind::WorkState],
    'missing attempt' => ['attempt_missing', InvariantKind::AttemptState],
    'foreign attempt work' => ['attempt_work', InvariantKind::AttemptState],
    'wrong attempt generation' => ['attempt_generation', InvariantKind::AttemptState],
    'wrong owner fingerprint' => ['attempt_token', InvariantKind::AttemptState],
    'closed current attempt' => ['attempt_finished', InvariantKind::AttemptState],
    'half finished attempt' => ['attempt_half_finished', InvariantKind::AttemptState],
]);

test('invariant lane counts: outstanding W и running C считаются по реальным members; limits и reservations не увеличиваются/не удаляются', function (): void {
    foreach (range(1, 2) as $i) {
        FinalizeFixtures::running(ExecutionFixtures::listingWork());
    }
    DB::update("UPDATE prc_process_lanes SET max_outstanding = 1, max_running = 1, low_watermark = 0, refill_max = 1 WHERE lane_key = 'acquisition.listing'");
    expect(app(InvariantScan::class)->reconcile())->toBe(2)
        ->and(DB::table('prc_process_incidents')->orderBy('kind')->pluck('kind')->all())->toBe(['invariant_lane_outstanding', 'invariant_lane_running'])
        ->and(ExecutionFixtures::laneCounts('acquisition.listing'))->toBe(['outstanding' => 2, 'running' => 2])
        ->and(DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->value('max_outstanding'))->toBe(1);
});

test('invariant bounded progress: более 50 corrupt rows после healthy prefix, restart продолжает по durable incidents без starvation', function (): void {
    foreach (range(1, 55) as $i) {
        ExecutionFixtures::listingWork();
    }
    foreach (range(1, 55) as $i) {
        $work = ExecutionFixtures::listingWork();
        // Deliberately orphaned unfinished attempt on ready work, no queue delivery/grant.
        DB::insert("INSERT INTO prc_process_attempts (id, work_id, generation, owner_token_fingerprint, handler_version, started_at, worker_host, worker_pid) VALUES (?, ?, 1, decode(?, 'hex'), ?, clock_timestamp(), 'fixture', 1)", [LedgerFixtures::uuid()->value, $work->value->value, LedgerFixtures::hash('orphan')->hex, ExecutionFixtures::handlerVersion(StageCode::Listing)]);
    }
    expect(app(InvariantScan::class)->reconcile())->toBe(50)
        ->and(DB::table('prc_process_incidents')->count())->toBe(50)
        ->and(app(InvariantScan::class)->reconcile())->toBe(5)
        ->and(app(InvariantScan::class)->reconcile())->toBe(0)
        ->and(DB::table('prc_process_incidents')->count())->toBe(55)
        ->and((int) DB::table('prc_process_incidents')->sum('occurrences'))->toBe(55)
        ->and(DB::table('prc_process_attempts')->count())->toBe(55)
        ->and(DB::table('prc_process_work')->where('state', 'ready')->count())->toBe(110)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe(0)
        ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->count())->toBe(1);
});

test('invariant atomic policy: incident journal failure rolls back incident, pause, control version and projection; restart detects again', function (): void {
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    invariantCorruptHash($work);
    $before = DB::table('prc_process_outbox')->count();
    DB::unprepared("CREATE FUNCTION fixture_invariant_reject() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.entity_kind = 'incident' THEN RAISE EXCEPTION 'fixture invariant journal failure'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER fixture_invariant_reject BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION fixture_invariant_reject()');
    try {
        expect(fn () => app(InvariantScan::class)->reconcile())->toThrow(QueryException::class, 'fixture invariant journal failure');
        expect(DB::table('prc_process_incidents')->count())->toBe(0)
            ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->first())->toMatchObject(['desired_mode' => 'enabled', 'control_version' => 1])
            ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->count())->toBe(0)
            ->and(DB::table('prc_process_outbox')->count())->toBe($before);
    } finally {
        DB::unprepared('DROP TRIGGER fixture_invariant_reject ON prc_process_transitions');
        DB::unprepared('DROP FUNCTION fixture_invariant_reject()');
    }
    expect(app(InvariantScan::class)->reconcile())->toBe(1);
});

test('invariant preserves manual pause and detects again after resume without duplicating active incident', function (): void {
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    invariantCorruptHash($work);
    ExecutionFixtures::setMode('system', ControlMode::Paused);
    $reason = DB::table('prc_control_scopes')->where('scope_key', 'system')->value('change_reason');
    expect(app(InvariantScan::class)->reconcile())->toBe(1)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->value('control_version'))->toBe(2)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->value('change_reason'))->toBe($reason);
    ExecutionFixtures::setMode('system', ControlMode::Enabled);
    expect(app(InvariantScan::class)->reconcile())->toBe(1)
        ->and(DB::table('prc_process_incidents')->count())->toBe(1)
        ->and(DB::table('prc_process_incidents')->value('occurrences'))->toBe(1)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->value('desired_mode'))->toBe('paused');
});

test('invariant defers nested scan without taking read locks or advancing cadence until caller commits', function (): void {
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    invariantCorruptHash($work);
    $scan = app(InvariantScan::class);
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'WITH required(')) {
            $reads++;
        }
    });
    DB::beginTransaction();
    try {
        expect([$scan->reconcile(), $reads])->toBe([0, 0])
            ->and(DB::table('prc_process_incidents')->count())->toBe(0)
            ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->value('desired_mode'))->toBe('enabled');
    } finally {
        DB::rollBack();
    }
    expect([$scan->reconcile(), $reads])->toBe([1, 1]);
});

test('invariant carrier validates predicate subject and bounded batch rejects zero or more than 50', function (): void {
    expect(fn () => new InvariantViolation(InvariantKind::LaneRunning, 'work:'.LedgerFixtures::uuid()->value))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new InvariantViolation(InvariantKind::WorkState, 'work:invalid'))->toThrow(InvalidArgumentException::class);
    foreach ([0, 51] as $limit) {
        expect(fn () => app(ProcessTransaction::class)->run(
            fn () => app(InvariantRepository::class)->readBatch($limit),
        ))->toThrow(InvalidArgumentException::class);
    }
});
