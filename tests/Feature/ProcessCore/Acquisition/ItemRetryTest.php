<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelRun;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Models\User;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('acquisition-operations', 'run-state-reduction');

test('item retry is audited deduplicated and preserves failures and cancelled shared-run provenance', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    FinalizeFixtures::authority([StageCode::Detail]);
    $work = ExecutionFixtures::detailWork();
    $first = LedgerFixtures::crawlBlock()['run'];
    $second = LedgerFixtures::crawlBlock()['run'];
    foreach ([$first, $second] as $run) {
        app(WorkStoreRepository::class)->linkRun($run, $work, RunWorkRelation::Created, AccountingRole::Required);
    }
    $seq = DB::table('prc_process_runs')->where('id', $first->value)->value('transition_seq');
    app(CancelRun::class)->execute($first, 'cancel only this run', $seq);
    expect(ExecutionFixtures::work($work)->state)->toBe('ready');
    $failure = new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'reject', LedgerFixtures::hash('reject')));
    foreach ([1, 2, 3] as $attempt) {
        $lease = $attempt === 1 ? FinalizeFixtures::running($work) : FinalizeFixtures::rerun($work);
        app(WorkFinalizer::class)->finish($lease, $failure);
    }
    $old = ExecutionFixtures::work($work);
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $analyst = User::factory()->create();
    $analyst->assignRole('analyst');
    $commands = app(ProcessOperatorCommands::class);
    $command = LedgerFixtures::uuid();
    expect(fn () => $commands->retryFailedItem($analyst, $work, $old->transition_seq, $command, 'corrected input'))
        ->toThrow(AuthorizationException::class);
    expect($commands->retryFailedItem($operator, $work, $old->transition_seq - 1, $command, 'corrected input')->status)
        ->toBe(OperatorCommandStatus::VersionConflict);
    $result = $commands->retryFailedItem($operator, $work, $old->transition_seq, $command, 'corrected input');
    expect($result->status)->toBe(OperatorCommandStatus::Applied)->and($result->successor)->not->toBeNull();
    $retry = $result->successor;
    expect($commands->retryFailedItem($operator, $work, $old->transition_seq, $command, 'corrected input')->status)->toBe(OperatorCommandStatus::Duplicate);
    $preserved = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
    $fresh = DB::table('prc_process_work')->where('id', $retry->value->value)->sole();
    expect([$preserved->state, $preserved->item_failures, $preserved->reason_code])->toBe(['failed_item', 3, 'contract_rejected'])
        ->and([$fresh->state, $fresh->item_failures])->toBe(['ready', 0])
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count())->toBe(3)
        ->and(DB::table('prc_process_run_work')->where('run_id', $first->value)->where('work_id', $retry->value->value)->value('accounting_role'))->toBe('observes')
        ->and(DB::table('prc_process_run_work')->where('run_id', $second->value)->where('work_id', $retry->value->value)->value('accounting_role'))->toBe('required');
    $audit = DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('reason_code', 'manual_retry')->sole();
    expect($audit->actor_kind)->toBe('operator')->and($audit->actor_id)->toBe($operator->id);
});
