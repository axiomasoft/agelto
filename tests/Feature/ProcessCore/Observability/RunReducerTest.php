<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RunFacts;
use App\Dto\Procurement\Process\WorkStateCounts;
use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\ProcessRunState;
use App\Services\Procurement\Process\RunProjector;
use App\Services\Procurement\Process\RunStateReducer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('acquisition-operations', 'run-state-reduction');

test('pure reducer: таблица состояний конечного run (open/closed, required, cancel, issues)', function (
    bool $cancelled,
    DiscoveryOutcome $discovery,
    bool $activated,
    WorkStateCounts $required,
    ProcessRunState $expected,
): void {
    expect((new RunStateReducer)->reduce(new RunFacts($cancelled, $discovery, $activated, $required)))->toBe($expected);
})->with([
    'open empty до активации' => [false, DiscoveryOutcome::Collecting, false, new WorkStateCounts, ProcessRunState::Requested],
    'open empty после активации остаётся running' => [false, DiscoveryOutcome::Collecting, true, new WorkStateCounts, ProcessRunState::Running],
    'open, все required terminal — всё ещё running (discovery не закрыт)' => [false, DiscoveryOutcome::Collecting, true, new WorkStateCounts(succeeded: 3), ProcessRunState::Running],
    'closed empty complete' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts, ProcessRunState::Completed],
    'closed, required succeeded' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(succeeded: 2), ProcessRunState::Completed],
    'required ready/admitted/running продвигаются' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(admitted: 1, succeeded: 1), ProcessRunState::Running],
    'только deferred/blocked — waiting' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(deferred: 1, blocked: 1, succeeded: 1), ProcessRunState::Waiting],
    'waiting не перебивает продвигающийся required' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(running: 1, deferred: 2), ProcessRunState::Running],
    'waiting при открытом discovery' => [false, DiscoveryOutcome::Collecting, true, new WorkStateCounts(blocked: 1), ProcessRunState::Waiting],
    'failed_item — completed_with_issues' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(succeeded: 1, failedItem: 1), ProcessRunState::CompletedWithIssues],
    'индивидуально cancelled work — completed_with_issues' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(cancelled: 1), ProcessRunState::CompletedWithIssues],
    'superseded без successor — не успех' => [false, DiscoveryOutcome::Complete, true, new WorkStateCounts(superseded: 1), ProcessRunState::CompletedWithIssues],
    'incomplete discovery даже без failures' => [false, DiscoveryOutcome::Incomplete, true, new WorkStateCounts(succeeded: 2), ProcessRunState::CompletedWithIssues],
    'explicit cancel терминален независимо от required' => [true, DiscoveryOutcome::Incomplete, true, new WorkStateCounts(running: 1), ProcessRunState::Cancelled],
    'cancel не отменяется поздним успехом' => [true, DiscoveryOutcome::Incomplete, true, new WorkStateCounts(succeeded: 5), ProcessRunState::Cancelled],
]);

test('WorkStateCounts отвергает неизвестное состояние и считает группы без пропусков', function (): void {
    $counts = WorkStateCounts::fromMap(['ready' => 1, 'admitted' => 2, 'running' => 3, 'deferred' => 4, 'blocked' => 5, 'succeeded' => 6, 'failed_item' => 7, 'cancelled' => 8, 'superseded' => 9]);

    expect($counts->active())->toBe(6)
        ->and($counts->waiting())->toBe(9)
        ->and($counts->terminal())->toBe(30)
        ->and($counts->total())->toBe(45)
        ->and(fn () => WorkStateCounts::fromMap(['partial' => 1]))->toThrow(LogicException::class);
});

/** Состояние work, удовлетворяющее CHECK (lease/claim/due/reason) для прямой вставки fixture. */
function runWork(ProcessUuid $block, string $state): ProcessUuid
{
    $overrides = match ($state) {
        'ready', 'succeeded' => ['state' => $state],
        'admitted' => ['state' => 'admitted', 'dispatch_claim_until' => DB::raw("clock_timestamp() + interval '1 minute'")],
        'running' => ['state' => 'running', 'owner_token' => LedgerFixtures::uuid()->value, 'lease_until' => DB::raw("clock_timestamp() + interval '1 minute'")],
        'deferred' => ['state' => 'deferred', 'reason_code' => 'item_retry', 'due_at' => DB::raw("clock_timestamp() + interval '1 minute'")],
        default => ['state' => $state, 'reason_code' => 'fixture'],
    };

    return LedgerFixtures::insertRawWork($block, $overrides);
}

function runLink(ProcessUuid $run, ProcessUuid $work, string $relation, string $role): void
{
    DB::table('prc_process_run_work')->insert(['run_id' => $run->value, 'work_id' => $work->value, 'relation' => $relation, 'accounting_role' => $role]);
}

function closeDiscovery(ProcessUuid $run, string $outcome = 'complete'): void
{
    DB::table('prc_process_runs')->where('id', $run->value)->update([
        'discovery_outcome' => $outcome,
        'discovery_closed_at' => DB::raw('clock_timestamp()'),
        'discovery_reason_code' => $outcome === 'incomplete' ? 'source_cap' : null,
    ]);
}

test('run из БД: open empty остаётся requested, ожидаемый итог неизвестен (percent null)', function (): void {
    $fixture = LedgerFixtures::crawlBlock();

    $summary = app(RunProjector::class)->summary($fixture['run']);

    expect($summary?->state)->toBe(ProcessRunState::Requested)
        ->and($summary?->plannedFinal())->toBeFalse()
        ->and($summary?->percent())->toBeNull()
        ->and(app(RunProjector::class)->summary(LedgerFixtures::uuid()))->toBeNull();

    DB::table('prc_process_runs')->where('id', $fixture['run']->value)->update(['started_at' => DB::raw('clock_timestamp()')]);
    expect(app(RunProjector::class)->summary($fixture['run'])?->state)->toBe(ProcessRunState::Running);
});

test('closed empty run — completed со 100%; закрытие после drain required даёт completed, до него — running', function (): void {
    $empty = LedgerFixtures::crawlBlock();
    closeDiscovery($empty['run']);
    $emptySummary = app(RunProjector::class)->summary($empty['run']);

    $run = LedgerFixtures::crawlBlock();
    $work = runWork($run['block'], 'running');
    runLink($run['run'], $work, 'created', 'required');
    $open = app(RunProjector::class)->summary($run['run']);
    DB::table('prc_process_work')->where('id', $work->value)->update(['state' => 'succeeded', 'owner_token' => null, 'lease_until' => null]);
    $stillCollecting = app(RunProjector::class)->summary($run['run']);
    closeDiscovery($run['run']);
    $closed = app(RunProjector::class)->summary($run['run']);

    expect($emptySummary?->state)->toBe(ProcessRunState::Completed)
        ->and($emptySummary?->percent())->toBe(100)
        ->and($open?->state)->toBe(ProcessRunState::Running)
        ->and($stillCollecting?->state)->toBe(ProcessRunState::Running)
        ->and($stillCollecting?->percent())->toBeNull()
        ->and($closed?->state)->toBe(ProcessRunState::Completed)
        ->and($closed?->percent())->toBe(100)
        ->and($closed?->executed)->toBe(1);
});

test('shared required work: считается в обоих runs, executed — только у creator', function (): void {
    $a = LedgerFixtures::crawlBlock();
    $b = LedgerFixtures::crawlBlock();
    $shared = runWork($a['block'], 'deferred');
    runLink($a['run'], $shared, 'created', 'required');
    runLink($b['run'], $shared, 'discovered', 'required');
    closeDiscovery($a['run']);
    closeDiscovery($b['run']);

    $first = app(RunProjector::class)->summary($a['run']);
    $second = app(RunProjector::class)->summary($b['run']);

    expect($first?->state)->toBe(ProcessRunState::Waiting)
        ->and($second?->state)->toBe(ProcessRunState::Waiting)
        ->and($first?->required->deferred)->toBe(1)
        ->and($second?->required->deferred)->toBe(1)
        ->and($first?->executed)->toBe(1)
        ->and($second?->executed)->toBe(0);

    DB::table('prc_process_work')->where('id', $shared->value)->update(['state' => 'succeeded', 'reason_code' => null, 'due_at' => null]);
    expect(app(RunProjector::class)->summary($a['run'])?->state)->toBe(ProcessRunState::Completed)
        ->and(app(RunProjector::class)->summary($b['run'])?->state)->toBe(ProcessRunState::Completed);
});

test('незавершённый downstream observes не удерживает acquisition run и показан отдельно', function (): void {
    $run = LedgerFixtures::crawlBlock();
    $detail = runWork($run['block'], 'succeeded');
    $document = runWork($run['block'], 'running');
    runLink($run['run'], $detail, 'created', 'required');
    runLink($run['run'], $document, 'observes', 'observes');
    closeDiscovery($run['run']);

    $summary = app(RunProjector::class)->summary($run['run']);

    expect($summary?->state)->toBe(ProcessRunState::Completed)
        ->and($summary?->observes)->toBe(1)
        ->and($summary?->required->running)->toBe(0)
        ->and($summary?->required->total())->toBe(1);
});

test('cancel: run остаётся cancelled, поздний receipt и завершение shared work не меняют итог', function (): void {
    $run = LedgerFixtures::crawlBlock();
    $work = runWork($run['block'], 'running');
    runLink($run['run'], $work, 'created', 'required');
    DB::table('prc_process_runs')->where('id', $run['run']->value)->update([
        'cancel_requested_at' => DB::raw('clock_timestamp()'), 'discovery_outcome' => 'incomplete',
        'discovery_closed_at' => DB::raw('clock_timestamp()'), 'discovery_reason_code' => 'cancelled',
    ]);

    expect(app(RunProjector::class)->summary($run['run'])?->state)->toBe(ProcessRunState::Cancelled);

    DB::table('prc_process_work')->where('id', $work->value)->update(['state' => 'succeeded', 'owner_token' => null, 'lease_until' => null]);

    expect(app(RunProjector::class)->summary($run['run'])?->state)->toBe(ProcessRunState::Cancelled);
});

test('summary читает header и links ОДНИМ statement: cancel и завершение shared work не смешиваются из разных snapshot', function (): void {
    $run = LedgerFixtures::crawlBlock();
    $work = runWork($run['block'], 'succeeded');
    runLink($run['run'], $work, 'created', 'required');
    closeDiscovery($run['run']);
    DB::table('prc_process_runs')->where('id', $run['run']->value)->update(['cancel_requested_at' => DB::raw('clock_timestamp()')]);

    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });
    $summary = app(RunProjector::class)->summary($run['run']);

    // Два отдельных чтения (header, затем links) допускали Completed между cancel и успехом work.
    expect($statements)->toHaveCount(1)
        ->and($summary?->state)->toBe(ProcessRunState::Cancelled)
        ->and($summary?->required->succeeded)->toBe(1);
});

test('superseded: заменённый link демотирован до observes, successor решает итог; без successor — issue', function (): void {
    $run = LedgerFixtures::crawlBlock();
    $old = runWork($run['block'], 'superseded');
    $successor = runWork($run['block'], 'running');
    // Атомарная замена: прежняя связь observes, successor наследует required.
    runLink($run['run'], $old, 'created', 'observes');
    runLink($run['run'], $successor, 'discovered', 'required');
    closeDiscovery($run['run']);

    expect(app(RunProjector::class)->summary($run['run'])?->state)->toBe(ProcessRunState::Running);

    DB::table('prc_process_work')->where('id', $successor->value)->update(['state' => 'succeeded', 'owner_token' => null, 'lease_until' => null]);
    expect(app(RunProjector::class)->summary($run['run'])?->state)->toBe(ProcessRunState::Completed);

    $orphan = LedgerFixtures::crawlBlock();
    $lost = runWork($orphan['block'], 'superseded');
    runLink($orphan['run'], $lost, 'created', 'required');
    closeDiscovery($orphan['run']);
    expect(app(RunProjector::class)->summary($orphan['run'])?->state)->toBe(ProcessRunState::CompletedWithIssues);
});

test('incomplete discovery (source cap) после drain — completed_with_issues, а не ложная полнота', function (): void {
    $run = LedgerFixtures::crawlBlock();
    $work = runWork($run['block'], 'succeeded');
    runLink($run['run'], $work, 'created', 'required');
    closeDiscovery($run['run'], 'incomplete');

    expect(app(RunProjector::class)->summary($run['run'])?->state)->toBe(ProcessRunState::CompletedWithIssues);
});

test('чтение run не блокирует и не меняет ledger: те же факты дают тот же результат при любом порядке событий', function (): void {
    $run = LedgerFixtures::crawlBlock();
    $work = runWork($run['block'], 'succeeded');
    runLink($run['run'], $work, 'created', 'required');
    closeDiscovery($run['run']);
    $runRow = fn () => DB::table('prc_process_runs')
        ->selectRaw("*, encode(configuration_hash, 'hex') as configuration_hash")
        ->where('id', $run['run']->value)->first();
    $before = $runRow();

    $states = [app(RunProjector::class)->summary($run['run'])?->state, app(RunProjector::class)->summary($run['run'])?->state];

    expect($states)->toBe([ProcessRunState::Completed, ProcessRunState::Completed])
        ->and($runRow())->toEqual($before);
});
