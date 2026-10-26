<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t09', 't06', 't24', 'p18', 'p111');

// Отдельные PHP процессы и PostgreSQL connections коммитят rows: Process ledger isolated
// agelto_test очищается целиком до и после. Revocation generation выполняют настоящие
// RecoveryReconciler (expired running) и CancelWork (hard cancel) под полным prefix (P1.11), а не
// fixture-мутатор generation.
beforeEach(fn () => ExecutionFixtures::truncateLedger());
afterEach(fn () => ExecutionFixtures::truncateLedger());

/** Тело worker: binding listing finalization в этом процессе + операция, результат — scalars. */
function fenceWorker(string $operation): string
{
    return '\Tests\Support\ProcessCore\FinalizeFixtures::bind(\App\Enums\Procurement\Process\StageCode::Listing);'
        .'$fx = \Tests\Support\ProcessCore\FinalizeFixtures::class;'
        .$operation;
}

function fenceLease(ExecutionLease $lease): string
{
    return "\\Tests\\Support\\ProcessCore\\FinalizeFixtures::import('".FinalizeFixtures::export($lease)."')";
}

function fenceWork(WorkId $work): string
{
    return "new \\App\\Dto\\Procurement\\Process\\WorkId(new \\App\\Dto\\Procurement\\Process\\ProcessUuid('".$work->value->value."'))";
}

function fenceFinish(ExecutionLease $lease, string $key): string
{
    return '$result = app(\App\Services\Procurement\Process\WorkFinalizer::class)->finish('.fenceLease($lease)
        .", \\Tests\\Support\\ProcessCore\\FinalizeFixtures::succeeded(\\App\\Enums\\Procurement\\Process\\StageCode::Listing, '".$key."'));"
        ."return ['status' => \$result->status->value, 'state' => \$result->state?->value];";
}

function fenceState(WorkId $work): object
{
    return DB::selectOne(
        'SELECT w.state, w.generation, w.owner_token, w.checkpoint_seq, b.cursor::text AS cursor, b.terminal_proof::text AS terminal_proof
         FROM prc_process_work w JOIN prc_crawl_blocks b ON b.id = w.crawl_block_id WHERE w.id = ?',
        [$work->value->value],
    );
}

function fenceListing(): array
{
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();

    return [$work, FinalizeFixtures::running($work)];
}

test('T09 D9 оба порядка: expired-but-unrecovered finalize выигрывает → один accepted commit; revocation выигрывает → ноль domain writes', function (bool $finalizeFirst): void {
    [$work, $lease] = fenceListing();
    FinalizeFixtures::expire($work);
    $finish = fenceFinish($lease, 'race');
    $revoke = '$recovered = app(\App\Services\Procurement\Process\RecoveryReconciler::class)->recoverExpiredRunning(); return ["status" => $recovered > 0 ? "revoked" : "noop"];';
    $workers = $finalizeFirst
        ? ['finalize' => fenceWorker('return $fx::holdUntilWaiter(function () { '.$finish.' });'), 'revoke' => fenceWorker('return $fx::afterHolder(function () use ($fx) { '.$revoke.' });')]
        : ['revoke' => fenceWorker('return $fx::holdUntilWaiter(function () use ($fx) { '.$revoke.' });'), 'finalize' => fenceWorker('return $fx::afterHolder(function () { '.$finish.' });')];

    $results = ConcurrentTransactions::concurrently($workers);
    $row = fenceState($work);
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();

    if ($finalizeFirst) {
        expect($results['finalize'])->toBe(['status' => 'applied', 'state' => 'succeeded'])
            ->and($results['revoke'])->toBe(['status' => 'noop'])
            ->and([$row->state, $row->generation, json_decode($row->terminal_proof, true)])->toBe(['succeeded', 1, ['artifact' => 'results/race']])
            ->and($group->release_reason)->toBe('succeeded');
    } else {
        expect($results['revoke'])->toBe(['status' => 'revoked'])
            ->and($results['finalize'])->toBe(['status' => 'lost_ownership', 'state' => null])
            ->and([$row->state, $row->generation, $row->owner_token, $row->terminal_proof])->toBe(['ready', 2, null, null])
            ->and($group->release_reason)->toBe('lease_recovery');
    }
    expect([$group->state, $group->version])->toBe(['released', 3])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_attempts')->whereNotNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_process_transitions')->where('work_id', $work->value->value)->whereIn('new_state', ['succeeded', 'ready'])->where('entity_seq', '>', 3)->count())->toBe(1)
        ->and(DB::table('prc_process_work')->count())->toBe(1);
})->with([
    'finalize держит prefix первым' => [true],
    'revocation держит prefix первой' => [false],
]);

test('T06 hard cancel живого lease и новый head сериализуются с finalize одной row: поздняя публикация невозможна', function (string $race): void {
    [$work, $lease] = fenceListing();
    $holder = match ($race) {
        'cancel' => 'app(\App\Actions\Procurement\Process\CancelWork::class)->execute('.fenceWork($work).", 'operator hard cancel'); return [\"held\" => \"cancel\"];",
        'head' => "\\Illuminate\\Support\\Facades\\DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1 WHERE id = ?', ['"
            .DB::table('prc_process_work')->where('id', $work->value->value)->value('crawl_block_id')."']); return ['held' => 'head'];",
    };
    $results = ConcurrentTransactions::concurrently([
        'holder' => fenceWorker('return $fx::holdUntilWaiter(function () use ($fx) { '.$holder.' });'),
        'finalize' => fenceWorker('return $fx::afterHolder(function () { '.fenceFinish($lease, 'late').' });'),
    ]);
    $row = fenceState($work);

    expect($results['finalize'])->toBe($race === 'cancel'
        ? ['status' => 'lost_ownership', 'state' => null]
        : ['status' => 'applied', 'state' => 'superseded'])
        ->and([$row->state, $row->generation, $row->terminal_proof])->toBe([$race === 'cancel' ? 'cancelled' : 'superseded', 2, null])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_attempts')->value('terminal_kind'))->toBe($race === 'cancel' ? 'cancelled' : 'superseded');
})->with(['cancel', 'head']);

test('T09 checkpoint во время revocation: lost CAS откатывает всё, domain cursor не пишется; живой owner сохраняет progress', function (): void {
    [$work, $lease] = fenceListing();
    $checkpoint = '$result = app(\App\Repositories\Procurement\Process\WorkStoreRepository::class)->checkpoint('.fenceLease($lease)
        .', new \Tests\Support\ProcessCore\FixtureStageCheckpoint(8), 0); return ["status" => $result->status->value];';
    $results = ConcurrentTransactions::concurrently([
        'cancel' => fenceWorker('return $fx::holdUntilWaiter(function () { app(\App\Actions\Procurement\Process\CancelWork::class)->execute('.fenceWork($work).", 'operator hard cancel'); return [\"held\" => true]; });"),
        'checkpoint' => fenceWorker('return $fx::afterHolder(function () { '.$checkpoint.' });'),
    ]);
    $row = fenceState($work);

    expect($results['checkpoint'])->toBe(['status' => 'lost_ownership'])
        ->and([$row->state, $row->checkpoint_seq, $row->cursor])->toBe(['cancelled', 0, '{}']);

    $live = ExecutionFixtures::listingWork();
    $liveLease = FinalizeFixtures::running($live);
    $saved = ConcurrentTransactions::execute(fenceWorker(
        '$result = app(\App\Repositories\Procurement\Process\WorkStoreRepository::class)->checkpoint('.fenceLease($liveLease)
        .', new \Tests\Support\ProcessCore\FixtureStageCheckpoint(8), 0); return ["status" => $result->status->value, "seq" => $result->checkpointSeq];',
    ));
    expect($saved)->toBe(['status' => 'saved', 'seq' => 1])
        ->and(json_decode(fenceState($live)->cursor, true))->toBe(['position' => 8]);
});

test('T24 pause scope во время 10 параллельных finalize: SHARE без upgrade, без deadlock, все полученные результаты приняты', function (): void {
    FinalizeFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 19, 'refill_max' => 10],
        'acquisition.listing' => ['max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 19, 'refill_max' => 10],
    ]);
    $works = array_map(fn (): WorkId => ExecutionFixtures::listingWork(), range(1, 10));
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(10);
    $workers = [];
    foreach ($works as $index => $work) {
        $lease = app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work))->lease ?? throw new LogicException('not claimed');
        $workers['finalize'.$index] = fenceWorker(fenceFinish($lease, 'w'.$index));
    }
    $workers['pause'] = "\$result = \\Tests\\Support\\ProcessCore\\ExecutionFixtures::setMode('acquisition', \\App\\Enums\\Procurement\\Process\\ControlMode::Paused);"
        ."return ['status' => \$result->status->value];";

    $results = ConcurrentTransactions::concurrently($workers);
    $finalized = array_filter($results, fn (string $name): bool => $name !== 'pause', ARRAY_FILTER_USE_KEY);

    expect($results['pause'])->toBe(['status' => 'applied'])
        ->and(array_unique(array_map(fn (array $result): string => $result['status'].':'.$result['state'], $finalized)))->toBe(['finalize0' => 'applied:succeeded'])
        ->and(DB::table('prc_process_work')->where('state', 'succeeded')->count())->toBe(10)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(10)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_admission_groups')->where('state', 'released')->count())->toBe(10)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('paused');
});

test('EXR-02 три unexpected faults одного input/version → deferred +60 s, +60 s, blocked(handler_fault_limit); journal rollback не расходует budget', function (): void {
    [$work, $lease] = fenceListing();
    $finalizer = app(WorkFinalizer::class);
    $id = $work->value->value;
    $faults = fn (): int => DB::table('prc_process_attempts')->where('work_id', $id)->whereNotNull('fault_signature')->whereNotNull('finished_at')->count();
    $dueIn = fn (): ?int => ($due = DB::selectOne('SELECT extract(epoch FROM due_at - clock_timestamp()) AS due FROM prc_process_work WHERE id = ?', [$id])->due) === null ? null : (int) round((float) $due);

    $first = $finalizer->finishHandlerFault($lease, FinalizeFixtures::fault('a'));
    expect([$first->state, ExecutionFixtures::work($work)->reason_code, $dueIn(), $faults()])->toBe([WorkState::Deferred, 'handler_fault', 60, 1]);

    // Journal insert второго fault падает (занятый entity_seq): весь finalize откатывается.
    $second = FinalizeFixtures::rerun($work);
    $seq = ExecutionFixtures::work($work)->transition_seq + 1;
    DB::insert(
        "INSERT INTO prc_process_transitions (event_id, entity_kind, work_id, entity_seq, new_state, actor_kind, schema_version) VALUES (?, 'work', ?, ?, 'blocker', 'system', 1)",
        [LedgerFixtures::uuid()->value, $id, $seq],
    );
    expect(fn () => $finalizer->finishHandlerFault($second, FinalizeFixtures::fault('b')))->toThrow(QueryException::class)
        ->and([ExecutionFixtures::work($work)->state, ExecutionFixtures::work($work)->generation, $faults()])->toBe(['running', 2, 1])
        ->and(DB::table('prc_process_attempts')->where('work_id', $id)->whereNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_process_reservations')->count())->toBe(2)
        ->and(DB::table('prc_process_incidents')->count())->toBe(0);
    DB::delete("DELETE FROM prc_process_transitions WHERE work_id = ? AND new_state = 'blocker'", [$id]);

    // Другая signature того же input/version не обнуляет budget.
    expect($finalizer->finishHandlerFault($second, FinalizeFixtures::fault('b'))->state)->toBe(WorkState::Deferred)
        ->and([$dueIn(), $faults()])->toBe([60, 2]);
    $third = $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('a'));
    $row = DB::table('prc_process_work')->where('id', $id)->sole();
    $incident = DB::table('prc_process_incidents')->sole();

    expect($third->state)->toBe(WorkState::Blocked)
        ->and([$row->state, $row->reason_code, $row->due_at, $row->provider_recovery_due_at, $row->item_failures])->toBe(['blocked', 'handler_fault_limit', null, null, 0])
        ->and($faults())->toBe(3)
        ->and([$incident->kind, $incident->state, $incident->scope_key])->toBe(['handler_fault_limit', 'open', 'work:'.$id])
        ->and(json_decode((string) $incident->evidence, true))->toEqual(['work_id' => $id, 'handler_version' => 'fixture-listing/1', 'faults' => 3])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        // Четвёртая delivery: blocked work не admitted, handler/attempt не выдаются; tick её не берёт.
        ->and(app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work))->status)->toBe(ClaimStatus::StaleDelivery)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(DB::table('prc_process_attempts')->where('work_id', $id)->count())->toBe(3)
        ->and(DB::table('prc_process_work')->where('id', $id)->value('state'))->toBe('blocked');

    // Stale finish другого input после revocation не добавляет fault в его budget.
    $other = ExecutionFixtures::listingWork();
    $otherLease = FinalizeFixtures::running($other);
    FinalizeFixtures::expire($other);
    expect(FinalizeFixtures::revoke($other))->toBeTrue()
        ->and($finalizer->finishHandlerFault($otherLease, FinalizeFixtures::fault('a'))->status)->toBe(CompletionStatus::LostOwnership)
        ->and(DB::table('prc_process_attempts')->where('work_id', $other->value->value)->whereNotNull('fault_signature')->count())->toBe(0);
});
