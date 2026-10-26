<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\StageCode;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t09', 'p111');

beforeEach(fn () => ExecutionFixtures::truncateLedger());
afterEach(fn () => ExecutionFixtures::truncateLedger());

/** One real running group, then one deliberately corrupt hash. No recovery fixture mutator. */
function invariantConcurrentFixture(): void
{
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    DB::update("UPDATE prc_admission_groups SET required_lane_set_hash = decode(?, 'hex') WHERE work_id = ?", [LedgerFixtures::hash('corrupt-hash')->hex, $work->value->value]);
}

/** Worker-local bounded PG barriers; they observe real locks, no mock or wall-clock sleeps. */
function invariantBarrierCode(): string
{
    return <<<'PHP'
    $await = static function (Closure $predicate): void {
        $deadline = hrtime(true) + 5_000_000_000;
        while (! $predicate()) {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('Invariant PG barrier timed out.');
            }
            usleep(5000);
        }
    };
    $signal = static fn (int $key): bool => (int) \Illuminate\Support\Facades\DB::selectOne(
        "SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND granted", [$key]
    )->n > 0;
    PHP;
}

test('T09 invariant PG barrier: все read locks освобождены до отдельного control UPDATE; concurrent capacity writer завершается без upgrade/deadlock', function (): void {
    invariantConcurrentFixture();
    $before = DB::table('prc_process_reservations')->orderBy('lane_id')->get()->toJson();
    $barriers = invariantBarrierCode();
    $scanner = $barriers.<<<'PHP'
    \Illuminate\Support\Facades\DB::statement("SET application_name = 'p11-invariant-reader'");
    $readLevel = null;
    $holdLevel = null;
    \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($await, $signal, &$readLevel, &$holdLevel): void {
        if (str_contains($query->sql, 'WITH required(')) {
            $readLevel = \Illuminate\Support\Facades\DB::connection()->transactionLevel();
            \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_lock(818011)');
            $await(static fn (): bool => $signal(818012));
        }
        if (str_contains($query->sql, "SELECT control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")) {
            $holdLevel = \Illuminate\Support\Facades\DB::connection()->transactionLevel();
        }
    });
    try {
        $changed = app(\App\Services\Procurement\Process\InvariantScan::class)->reconcile();
        return ['changed' => $changed, 'read_level' => $readLevel, 'hold_level' => $holdLevel,
            'final_level' => \Illuminate\Support\Facades\DB::connection()->transactionLevel()];
    } finally {
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_unlock(818011)');
    }
    PHP;
    $writer = $barriers.<<<'PHP'
    $await(static fn (): bool => $signal(818011));
    \Illuminate\Support\Facades\DB::beginTransaction();
    try {
        \Illuminate\Support\Facades\DB::statement("SET LOCAL lock_timeout = '2s'");
        // SHARE is compatible with the reader. It prevents the later control UPDATE.
        \Illuminate\Support\Facades\DB::select("SELECT id FROM prc_control_scopes WHERE scope_key = 'system' FOR SHARE");
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_lock(818012)');
        // Waits for reader's capacity SHARE. If the scanner upgrades without committing reads,
        // scanner waits for our control SHARE and we wait for its capacity: a real deadlock.
        \Illuminate\Support\Facades\DB::select('SELECT id FROM prc_process_lanes ORDER BY id FOR UPDATE');
        $await(static fn (): bool => (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT count(*) AS n FROM pg_stat_activity WHERE application_name = 'p11-invariant-reader' AND wait_event_type = 'Lock'"
        )->n === 1);
        $pausedBefore = \Illuminate\Support\Facades\DB::table('prc_control_scopes')->where('scope_key', 'system')->value('desired_mode');
        \Illuminate\Support\Facades\DB::commit();
        return ['capacity_acquired' => true, 'hold_waited' => true, 'mode_before_hold' => $pausedBefore];
    } catch (Throwable $failure) {
        \Illuminate\Support\Facades\DB::rollBack();
        throw $failure;
    } finally {
        \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_unlock(818012)');
    }
    PHP;
    $results = ConcurrentTransactions::concurrently(['scanner' => $scanner, 'writer' => $writer]);
    expect($results['scanner'])->toBe(['changed' => 1, 'read_level' => 1, 'hold_level' => 1, 'final_level' => 0])
        ->and($results['writer'])->toBe(['capacity_acquired' => true, 'hold_waited' => true, 'mode_before_hold' => 'enabled'])
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'system')->first())->toMatchObject(['desired_mode' => 'paused', 'control_version' => 2])
        ->and(DB::table('prc_process_incidents')->where('kind', 'invariant_group_membership')->count())->toBe(1)
        ->and(DB::table('prc_process_reservations')->orderBy('lane_id')->get()->toJson())->toBe($before);
});

test('T09 invariant multi-instance: два overlapping read snapshots дают один durable incident и один hold, restart no-op без duplicate repair', function (): void {
    invariantConcurrentFixture();
    $barriers = invariantBarrierCode();
    $worker = static function (int $mine, int $other) use ($barriers): string {
        return $barriers.'$mine = '.$mine.'; $other = '.$other.';'.<<<'PHP'
        \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($mine, $other, $await, $signal): void {
            if (str_contains($query->sql, 'WITH required(')) {
                \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_lock(?)', [$mine]);
                $await(static fn (): bool => $signal($other));
            }
        });
        try {
            return ['changed' => app(\App\Services\Procurement\Process\InvariantScan::class)->reconcile()];
        } finally {
            \Illuminate\Support\Facades\DB::selectOne('SELECT pg_advisory_unlock(?)', [$mine]);
        }
        PHP;
    };
    $results = ConcurrentTransactions::concurrently(['one' => $worker(818013, 818014), 'two' => $worker(818014, 818013)]);
    $restart = ConcurrentTransactions::execute('return ["changed" => app(\App\Services\Procurement\Process\InvariantScan::class)->reconcile()];');
    $incident = DB::table('prc_process_incidents')->sole();
    expect($results['one']['changed'] + $results['two']['changed'])->toBe(1)
        ->and($restart)->toBe(['changed' => 0])
        ->and([$incident->kind, $incident->occurrences, $incident->transition_seq])->toBe(['invariant_group_membership', 1, 1])
        ->and(DB::table('prc_process_transitions')->where('entity_kind', 'control')->count())->toBe(1)
        ->and(DB::table('prc_process_transitions')->where('incident_id', $incident->id)->count())->toBe(1)
        ->and(DB::table('prc_process_outbox')->where('kind', 'projection')->count())->toBe(DB::table('prc_process_transitions')->count())
        ->and(DB::table('prc_admission_groups')->where('state', 'running')->count())->toBe(1)
        ->and(DB::table('prc_process_reservations')->count())->toBe(2)
        ->and(DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->value('max_outstanding'))->toBe(10);
});

test('T09 invariant healthy PG race: scan ждёт capacity полноценного finalize и не видит ложный partial group', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::export(FinalizeFixtures::running($work));
    $finish = '\\Tests\\Support\\ProcessCore\\FinalizeFixtures::bind(\\App\\Enums\\Procurement\\Process\\StageCode::Listing);'
        .'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () {'
        .'$result = app(\\App\\Services\\Procurement\\Process\\WorkFinalizer::class)->finish('
        ."\\Tests\\Support\\ProcessCore\\FinalizeFixtures::import('".$lease."'),"
        .'\\Tests\\Support\\ProcessCore\\FinalizeFixtures::succeeded(\\App\\Enums\\Procurement\\Process\\StageCode::Listing, "healthy-race"));'
        .'return ["status" => $result->status->value]; });';
    $scan = 'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () {'
        .'return ["changed" => app(\\App\\Services\\Procurement\\Process\\InvariantScan::class)->reconcile()]; });';
    $results = ConcurrentTransactions::concurrently(['finish' => $finish, 'scan' => $scan]);
    expect($results['finish'])->toBe(['status' => 'applied'])
        ->and($results['scan'])->toBe(['changed' => 0])
        ->and(ExecutionFixtures::work($work)->state)->toBe('succeeded')
        ->and(DB::table('prc_process_incidents')->count())->toBe(0)
        ->and(DB::table('prc_control_scopes')->where('desired_mode', 'paused')->count())->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
});
