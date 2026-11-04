<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelRun;
use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('work-controls-and-fences', 'claim-recovery-and-invariants', 'acquisition-operations');

// CancelWork / CancelRun на реальном ledger: hard cancel отзывает владельца generation, CancelRun
// отменяет намерение run и не трогает shared work других runs.

function controlRun(ProcessUuid $run): object
{
    return DB::table('prc_process_runs')->where('id', $run->value)->first(['cancel_requested_at', 'discovery_outcome', 'discovery_reason_code', 'discovery_closed_at', 'transition_seq']);
}

test('CancelWork живого running: generation+1, attempt cancelled, вся group released, audit с actor и reason; поздний finalize владельца отвергнут', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $resolver = FixtureProviderRecoveryResolver::install();
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $seq = ExecutionFixtures::work($work)->transition_seq;
    $cancel = app(CancelWork::class);

    expect($cancel->execute($work, 'stale operator view', $seq - 1)->status)->toBe(WorkMutationStatus::VersionConflict)
        ->and(ExecutionFixtures::work($work)->state)->toBe('running');

    expect($cancel->execute($work, 'operator cancel', $seq, 5)->status)->toBe(WorkMutationStatus::Applied);

    $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    $attempt = DB::table('prc_process_attempts')->where('work_id', $work->value->value)->sole();
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    $audit = DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'cancelled')->sole();
    expect([$row->state, $row->reason_code, $row->generation, $row->owner_token, $row->lease_until, $row->checkpoint])->toBe(['cancelled', 'cancelled', 2, null, null, null])
        ->and([$attempt->terminal_kind, $attempt->finished_at !== null])->toBe(['cancelled', true])
        ->and([$group->state, $group->release_reason])->toBe(['released', 'cancelled'])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and([$audit->previous_state, $audit->actor_kind, $audit->actor_id, json_decode((string) $audit->details, true)])->toBe(['running', 'operator', 5, ['reason' => 'operator cancel']])
        ->and($resolver->revoked)->toBe([$work->value->value]);

    // Claimed physical send мог уйти: поздняя публикация владельца всё равно невозможна.
    expect(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'late'))->status)->toBe(CompletionStatus::LostOwnership)
        ->and($results->resultWrites)->toBe(0)
        ->and($cancel->execute($work, 'again')->status)->toBe(WorkMutationStatus::Terminal)
        ->and(ExecutionFixtures::work($work)->generation)->toBe(2)
        ->and($cancel->execute(new WorkId(LedgerFixtures::uuid()), 'missing')->status)->toBe(WorkMutationStatus::NotFound);
});

test('CancelWork любого nonterminal состояния: ready, admitted, deferred, blocked; admission group освобождается, terminal не переписывается', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $cancel = app(CancelWork::class);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');

    $ready = ExecutionFixtures::listingWork();
    expect($cancel->execute($ready, 'ready cancel')->status)->toBe(WorkMutationStatus::Applied);

    $admitted = ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1)
        ->and(DB::table('prc_process_reservations')->where('work_id', $admitted->value->value)->count())->toBe(2)
        ->and($cancel->execute($admitted, 'admitted cancel')->status)->toBe(WorkMutationStatus::Applied)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);

    $deferred = ExecutionFixtures::listingWork();
    app(WorkFinalizer::class)->finishHandlerFault(FinalizeFixtures::running($deferred), FinalizeFixtures::fault('a'));
    expect(ExecutionFixtures::work($deferred)->state)->toBe('deferred')
        ->and($cancel->execute($deferred, 'deferred cancel')->status)->toBe(WorkMutationStatus::Applied);

    $blocked = ExecutionFixtures::listingWork();
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($blocked), FinalizeFixtures::fault('a'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($blocked), FinalizeFixtures::fault('b'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($blocked), FinalizeFixtures::fault('c'));
    expect(ExecutionFixtures::work($blocked)->state)->toBe('blocked')
        ->and($cancel->execute($blocked, 'blocked cancel')->status)->toBe(WorkMutationStatus::Applied);

    foreach ([$ready, $admitted, $deferred, $blocked] as $work) {
        $row = ExecutionFixtures::work($work);
        expect([$row->state, $row->reason_code])->toBe(['cancelled', 'cancelled']);
    }
    $succeeded = ExecutionFixtures::listingWork();
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($succeeded), FinalizeFixtures::succeeded(StageCode::Listing, 'done'));
    expect($cancel->execute($succeeded, 'too late')->status)->toBe(WorkMutationStatus::Terminal)
        ->and(ExecutionFixtures::work($succeeded)->state)->toBe('succeeded');
});

test('CancelRun отменяет намерение run, а не shared work: work завершается, cancelled run не получает новых required links, второй run сохраняет provenance', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $cancelled = LedgerFixtures::crawlBlock()['run'];
    $other = LedgerFixtures::crawlBlock()['run'];
    $store = app(WorkStoreRepository::class);
    $store->linkRun($cancelled, $work, RunWorkRelation::Created, AccountingRole::Required);
    $store->linkRun($other, $work, RunWorkRelation::Discovered, AccountingRole::Required);
    $successorInput = LedgerFixtures::statusInput(Procurement::factory()->create()->id);
    $results->successors = [$successorInput];
    $lease = FinalizeFixtures::running($work);
    $cancelRun = app(CancelRun::class);

    // Optimistic transition_seq: чужая версия run не отменяется.
    expect($cancelRun->execute($other, 'stale view', 99)->status)->toBe(WorkMutationStatus::VersionConflict)
        ->and(controlRun($other)->cancel_requested_at)->toBeNull();

    $seq = controlRun($cancelled)->transition_seq;
    expect($cancelRun->execute($cancelled, 'operator cancels run', $seq, 3)->status)->toBe(WorkMutationStatus::Applied);
    $run = controlRun($cancelled);
    $event = DB::table('prc_process_transitions')->where('run_id', $cancelled->value)->where('entity_seq', $seq + 1)->sole();
    expect($run->cancel_requested_at)->not->toBeNull()
        ->and([$run->discovery_outcome, $run->discovery_reason_code, $run->transition_seq])->toBe(['incomplete', 'cancelled', $seq + 1])
        ->and($run->discovery_closed_at)->not->toBeNull()
        ->and([$event->previous_state, $event->new_state, $event->reason_code, $event->actor_kind])->toBe(['collecting', 'incomplete', 'cancelled', 'operator'])
        ->and([$event->actor_id, json_decode((string) $event->details, true)])->toBe([3, ['reason' => 'operator cancels run']])
        ->and($cancelRun->execute($cancelled, 'repeat')->status)->toBe(WorkMutationStatus::Terminal)
        ->and($cancelRun->execute(new ProcessUuid(LedgerFixtures::uuid()->value), 'missing')->status)->toBe(WorkMutationStatus::NotFound)
        // Shared work не отменён: владелец живой, generation прежняя.
        ->and([ExecutionFixtures::work($work)->state, ExecutionFixtures::work($work)->generation])->toBe(['running', 1]);

    expect(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'shared'))->status)->toBe(CompletionStatus::Applied);
    $successor = DB::table('prc_process_work')->where('stage', 'status_refresh')->sole();
    $links = DB::table('prc_process_run_work')->where('work_id', $successor->id)->pluck('accounting_role', 'run_id')->all();
    expect($links)->toBe([$other->value => 'required'])
        // Provenance cancelled run к исходной работе сохранён.
        ->and(DB::table('prc_process_run_work')->where('run_id', $cancelled->value)->where('work_id', $work->value->value)->value('accounting_role'))->toBe('required')
        ->and(ExecutionFixtures::work($work)->state)->toBe('succeeded');
});

test('cancel не освобождает повреждённую группу и не отзывает владельца; cancelled run не принимает новый required link', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $member = DB::table('prc_process_reservations')->where('work_id', $work->value->value)->first();
    DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ? AND lane_id = ?', [$member->admission_group_id, $member->lane_id]);
    expect(fn () => app(CancelWork::class)->execute($work, 'corrupt membership'))->toThrow(LogicException::class)
        ->and([ExecutionFixtures::work($work)->state, ExecutionFixtures::work($work)->generation])->toBe(['running', $lease->generation])
        ->and(DB::table('prc_process_reservations')->where('work_id', $work->value->value)->count())->toBe(1)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNull('finished_at')->count())->toBe(1);

    $run = LedgerFixtures::crawlBlock()['run'];
    app(CancelRun::class)->execute($run, 'closed run');
    expect(fn () => app(WorkStoreRepository::class)->linkRun($run, $work, RunWorkRelation::Discovered, AccountingRole::Required))->toThrow(LogicException::class)
        ->and(DB::table('prc_process_run_work')->where('run_id', $run->value)->count())->toBe(0);
});

test('shared successor: поздний required producer повышает observes link, последующий observer не понижает required', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $run = LedgerFixtures::crawlBlock()['run'];
    $results->successors = [LedgerFixtures::statusInput(Procurement::factory()->create()->id)];
    foreach ([AccountingRole::Observes, AccountingRole::Required, AccountingRole::Observes] as $role) {
        $work = ExecutionFixtures::listingWork();
        app(WorkStoreRepository::class)->linkRun($run, $work, RunWorkRelation::Discovered, $role);
        expect(app(WorkFinalizer::class)->finish(FinalizeFixtures::running($work), FinalizeFixtures::succeeded(StageCode::Listing, 'shared-'.$role->value))->status)->toBe(CompletionStatus::Applied);
        $successor = DB::table('prc_process_work')->where('stage', 'status_refresh')->sole();
        $expected = $role === AccountingRole::Required || $results->resultWrites > 1 ? 'required' : 'observes';
        expect(DB::table('prc_process_run_work')->where('run_id', $run->value)->where('work_id', $successor->id)->value('accounting_role'))->toBe($expected);
    }
    expect(DB::table('prc_process_work')->where('stage', 'status_refresh')->count())->toBe(1);
})->group('run-state-reduction');
