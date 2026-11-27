<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\RetryHandlerFaultedWork;
use App\Dto\Procurement\Process\Execution\DeliveryEnvelope;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Execution\WorkIdentity;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\MissingParts;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Enums\Procurement\Process\Outcomes\DeliveryOutcome;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Enums\Procurement\Process\Outcomes\WorkMutationStatus;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Execution\WorkRunner;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('claim-recovery-and-invariants', 'acquisition-operations');

// на реальном ledger: bounded per-work faults, historical global detector и manual retry dedup.

/** Один unexpected fault свежей work: отдельный input_hash, deferred(handler_fault) +60 s. */
function faultOnFreshWork(string $signature = 'a'): WorkId
{
    $work = ExecutionFixtures::listingWork();
    app(WorkFinalizer::class)->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault($signature));

    return $work;
}

/** Три faults одной work: deferred, deferred, blocked(handler_fault_limit). */
function faultToLimit(WorkId $work): void
{
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault('a'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('b'));
    $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('a'));
}

function faultHold(StageCode $stage = StageCode::Listing, string $version = 'fixture-listing/1'): object
{
    return DB::table('prc_process_handler_holds')->where('stage', $stage->value)->where('handler_version', $version)->sole();
}

test('historical detector: три distinct input одной signature за 5 минут → held version + incident; crash между finish и detector не теряет hold, повтор идемпотентен', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    foreach (['a', 'b', 'c'] as $site) {
        faultOnFreshWork();
    }
    // Detector не вызывался: finalize/logger hold не ставят.
    expect(faultHold()->state)->toBe('healthy')
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->whereNull('fault_policy_checked_at')->count())->toBe(3);

    // Простой больше 5 минут: окно отсчитывается от finished_at кандидата, а не от now.
    DB::update("UPDATE prc_process_attempts SET finished_at = finished_at - interval '10 minutes' WHERE fault_signature IS NOT NULL");

    expect(app(RecoveryReconciler::class)->sweepHandlerFaults())->toBe(3);

    $hold = faultHold();
    $incident = DB::table('prc_process_incidents')->where('kind', 'handler_hold')->sole();
    expect([$hold->state, $hold->version])->toBe(['held', 2])
        ->and($hold->incident_id)->toBe($incident->id)
        ->and([$incident->state, $incident->scope_key])->toBe(['open', 'handler:listing:fixture-listing/1'])
        // jsonb не сохраняет порядок ключей, поэтому сравнение без учёта порядка.
        ->and(json_decode((string) $incident->evidence, true))->toEqual(['stage' => 'listing', 'handler_version' => 'fixture-listing/1', 'distinct_inputs' => 3])
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->whereNull('fault_policy_checked_at')->count())->toBe(0)
        // Повтор идемпотентен: ни второго incident, ни нового version.
        ->and(app(RecoveryReconciler::class)->sweepHandlerFaults())->toBe(0)
        ->and(faultHold()->version)->toBe(2)
        ->and(DB::table('prc_process_incidents')->where('kind', 'handler_hold')->count())->toBe(1);

    // Held version запрещает новые grants, но не стирает manual mode.
    ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(DB::table('prc_control_scopes')->where('desired_mode', 'paused')->count())->toBe(0);

    // Новая версия получает свою healthy row и не закрывает incident старой.
    app(ProcessAuthoritySync::class)->registerHandler(StageCode::Listing, 'fixture-listing/2');
    expect(faultHold(version: 'fixture-listing/2')->state)->toBe('healthy')
        ->and(faultHold()->state)->toBe('held')
        ->and(DB::table('prc_process_incidents')->where('kind', 'handler_hold')->where('state', 'open')->count())->toBe(1);
});

test('detector: fault вне 5-минутного окна и повторы одного input порог не набирают; hold ставится только на version кандидата', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $old = faultOnFreshWork();
    faultOnFreshWork();
    faultOnFreshWork('other-signature');
    DB::update("UPDATE prc_process_attempts SET finished_at = finished_at - interval '10 minutes' WHERE work_id = ?", [$old->value->value]);

    // 2 same-signature inputs в окне кандидата + 1 вне окна + 1 другая signature: порога нет.
    expect(app(RecoveryReconciler::class)->sweepHandlerFaults())->toBe(3)
        ->and(faultHold()->state)->toBe('healthy')
        ->and(DB::table('prc_process_incidents')->where('kind', 'handler_hold')->count())->toBe(0);

    // Один постоянно падающий input: три faults одной work → local blocked, global distinct = 1.
    $single = ExecutionFixtures::listingWork();
    faultToLimit($single);
    expect(app(RecoveryReconciler::class)->sweepHandlerFaults())->toBe(3)
        ->and(faultHold()->state)->toBe('healthy');
    $row = DB::table('prc_process_work')->where('id', $single->value->value)->sole();
    expect([$row->state, $row->reason_code, $row->item_failures])->toBe(['blocked', 'handler_fault_limit', 0]);

    // Generic ResumeScope и reconcile категории work с исчерпанным budget не освобождают.
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    expect(app(RecoveryReconciler::class)->releaseBlocked())->toBe(0)
        ->and(app(RecoveryReconciler::class)->recoverAdmitted())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $single->value->value)->value('state'))->toBe('blocked');
});

test('RetryHandlerFaultedWork: новый manual intent/work со свежим budget, прежний superseded, required links перенесены; повтор команды — один work', function (): void {
    FinalizeFixtures::authority([StageCode::Detail]);
    $work = ExecutionFixtures::detailWork();
    $run = LedgerFixtures::crawlBlock()['run'];
    app(WorkStoreRepository::class)->linkRun($run, $work, RunWorkRelation::Created, AccountingRole::Required);
    faultToLimit($work);
    $blocked = ExecutionFixtures::work($work);
    expect([$blocked->state, $blocked->reason_code])->toBe(['blocked', 'handler_fault_limit']);
    $command = LedgerFixtures::uuid();

    $result = app(RetryHandlerFaultedWork::class)->execute($work, $blocked->transition_seq, $command, 'handler fixed in 1.2', 7);

    expect($result->status)->toBe(WorkMutationStatus::Applied)
        ->and($result->successor)->not->toBeNull();
    $successor = $result->successor;
    $old = ExecutionFixtures::work($work);
    $new = ExecutionFixtures::work($successor);
    $newRow = DB::table('prc_process_work')->where('id', $successor->value->value)->sole();
    $subject = $newRow->procurement_id;
    $intents = DB::table('prc_operation_intents')->where('stage', 'detail')->where('subject_id', (string) $subject)->get();
    expect([$old->state, $old->reason_code])->toBe(['superseded', 'manual_retry'])
        ->and([$new->state, $new->generation, $newRow->item_failures, $newRow->execution_attempts])->toBe(['ready', 0, 0, 0])
        // Новый manual intent даёт новый work_key (bytea отдаётся PDO как stream, поэтому считаем distinct в SQL).
        ->and((int) DB::table('prc_process_work')->whereIn('id', [$work->value->value, $successor->value->value])->selectRaw('count(DISTINCT work_key) AS keys')->value('keys'))->toBe(2)
        ->and($intents->where('state', 'active'))->toHaveCount(1)
        ->and($intents->where('state', 'active')->first()->id)->toBe($newRow->intent_id)
        ->and($intents->where('state', 'terminal'))->toHaveCount(1)
        // История сохранена: три faulted attempt прежнего work и exhaustion incident.
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNotNull('fault_signature')->count())->toBe(3)
        ->and(DB::table('prc_process_incidents')->where('kind', 'handler_fault_limit')->count())->toBe(1)
        ->and(DB::table('prc_process_run_work')->where('run_id', $run->value)->where('work_id', $successor->value->value)->value('accounting_role'))->toBe('required')
        ->and(DB::table('prc_process_run_work')->where('run_id', $run->value)->where('work_id', $work->value->value)->value('accounting_role'))->toBe('observes');
    $audit = DB::table('prc_process_transitions')->where('work_id', $work->value->value)->where('new_state', 'superseded')->sole();
    expect([$audit->actor_kind, $audit->actor_id, json_decode((string) $audit->details, true)['reason']])->toBe(['operator', 7, 'handler fixed in 1.2']);

    // Та же команда повторно: Duplicate, тот же successor, ни второго intent/work.
    $works = DB::table('prc_process_work')->count();
    $replay = app(RetryHandlerFaultedWork::class)->execute($work, $blocked->transition_seq, $command, 'handler fixed in 1.2', 7);
    expect([$replay->status, $replay->successor?->value->value])->toBe([WorkMutationStatus::Duplicate, $successor->value->value])
        ->and(DB::table('prc_process_work')->count())->toBe($works)
        ->and(DB::table('prc_operation_intents')->where('stage', 'detail')->where('state', 'active')->count())->toBe(1);

    // Другая команда с устаревшим expectedTransitionSeq: второй active intent не создаётся.
    $stale = app(RetryHandlerFaultedWork::class)->execute($work, $blocked->transition_seq, LedgerFixtures::uuid(), 'second operator', 8);
    expect($stale->status)->toBe(WorkMutationStatus::VersionConflict)
        ->and(DB::table('prc_operation_intents')->where('stage', 'detail')->where('state', 'active')->count())->toBe(1);

    // Свежий budget нового work: три faults снова нужны для limit.
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finishHandlerFault(FinalizeFixtures::running($successor), FinalizeFixtures::fault('a'));
    expect(ExecutionFixtures::work($successor)->state)->toBe('deferred');
});

test('RetryHandlerFaultedWork поддерживает listing и отказывает вне blocked limit или при нерешённой provider operation', function (): void {
    FinalizeFixtures::authority([StageCode::Listing, StageCode::Detail]);
    $retry = app(RetryHandlerFaultedWork::class);

    $listing = ExecutionFixtures::listingWork();
    faultToLimit($listing);
    $seq = ExecutionFixtures::work($listing)->transition_seq;
    expect($retry->execute($listing, $seq, LedgerFixtures::uuid(), 'retry listing')->status)->toBe(WorkMutationStatus::Applied)
        ->and(ExecutionFixtures::work($listing)->state)->toBe('superseded');

    $ready = ExecutionFixtures::detailWork();
    expect($retry->execute($ready, ExecutionFixtures::work($ready)->transition_seq, LedgerFixtures::uuid(), 'not blocked')->status)->toBe(WorkMutationStatus::NotEligible);

    $detail = ExecutionFixtures::detailWork();
    faultToLimit($detail);
    $plan = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($detail));
    $payload = app(StageRegistry::class)->payloadCodec()->encodeCheckpoint(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(0, StageCode::Detail), $plan));
    DB::update('UPDATE prc_process_work SET checkpoint = ?::jsonb, checkpoint_schema_version = ?, checkpoint_seq = 1 WHERE id = ?', [$payload->json, $payload->schemaVersion, $detail->value->value]);
    expect($retry->execute($detail, ExecutionFixtures::work($detail)->transition_seq, LedgerFixtures::uuid(), 'provider unresolved')->status)->toBe(WorkMutationStatus::ProviderUnresolved)
        ->and(ExecutionFixtures::work($detail)->state)->toBe('blocked')
        ->and(DB::table('prc_operation_intents')->where('stage', 'detail')->where('state', 'active')->count())->toBe(2);
});

test('manual retry listing/status сохраняет immutable input, получает documented key и command dedup; чужой work не может использовать command', function (StageCode $stage): void {
    FinalizeFixtures::authority([$stage]);
    $work = $stage === StageCode::Listing ? ExecutionFixtures::listingWork() : ExecutionFixtures::statusWork();
    faultToLimit($work);
    $before = DB::selectOne("SELECT transition_seq, input_payload::text AS input_payload, input_revision, encode(input_hash, 'hex') AS input_hash FROM prc_process_work WHERE id = ?", [$work->value->value]);
    $command = LedgerFixtures::uuid();
    $retry = app(RetryHandlerFaultedWork::class);
    $result = $retry->execute($work, $before->transition_seq, $command, 'fixed handler');
    expect($result->status)->toBe(WorkMutationStatus::Applied);
    $successor = $result->successor ?? throw new LogicException('Missing manual successor.');
    $after = DB::selectOne("SELECT state, input_payload::text AS input_payload, input_revision, encode(input_hash, 'hex') AS input_hash, work_identity::text AS work_identity, intent_id FROM prc_process_work WHERE id = ?", [$successor->value->value]);
    $identity = json_decode((string) $after->work_identity, true);
    expect([$after->state, $after->input_payload, $after->input_revision, $after->input_hash])
        ->toEqual(['ready', $before->input_payload, $before->input_revision, $before->input_hash])
        ->and($identity['manual_retry'])->toEqual(['intent_id' => $after->intent_id, 'retry_of_work_id' => $work->value->value])
        ->and($retry->execute($work, $before->transition_seq, $command, 'same command')->successor?->value->value)->toBe($successor->value->value)
        ->and(DB::table('prc_operation_intents')->where('stage', $stage->value)->where('state', 'active')->count())->toBe(1);

    $other = $stage === StageCode::Listing ? ExecutionFixtures::listingWork() : ExecutionFixtures::statusWork();
    expect($retry->execute($other, ExecutionFixtures::work($other)->transition_seq, $command, 'wrong work')->status)->toBe(WorkMutationStatus::VersionConflict)
        ->and(ExecutionFixtures::work($other)->state)->toBe('ready');
})->with([StageCode::Listing, StageCode::StatusRefresh]);

test('identity ручного retry требует одновременно intent и predecessor, обычный work_key сохраняется', function (): void {
    $input = LedgerFixtures::statusInput(Procurement::factory()->create()->id);
    $intent = LedgerFixtures::uuid();
    $predecessor = new WorkId(LedgerFixtures::uuid());
    expect(fn () => new WorkIdentity($input, $intent))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new WorkIdentity($input, retryOfWorkId: $predecessor))->toThrow(InvalidArgumentException::class)
        ->and((new WorkIdentity($input))->key()->hex)->toBe(ContentHash::of('work-identity:v1:'.(new ProcessPayloadCodec)->encodeInput($input)->json)->hex)
        ->and((new WorkIdentity($input, $intent, $predecessor))->key()->hex)->not->toBe((new WorkIdentity($input))->key()->hex);
    $count = DB::table('prc_process_work')->count();
    expect(fn () => app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
        id: new WorkId(LedgerFixtures::uuid()),
        input: $input,
        inputRevision: 1,
        intentId: null,
        cohortKey: 'manual',
        platformId: null,
        originRunId: null,
        priority: 100,
        eventId: LedgerFixtures::uuid(),
        projectionMessageId: LedgerFixtures::uuid(),
        identity: new WorkIdentity($input, $intent, $predecessor),
    )))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('prc_process_work')->count())->toBe($count);
});

test('historical fault detector включает нижнюю границу окна и не теряет unchecked evidence без authority row', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $works = [faultOnFreshWork(), faultOnFreshWork(), faultOnFreshWork()];
    DB::update("UPDATE prc_process_attempts SET finished_at = '2000-01-01 00:05:00+00' WHERE fault_signature IS NOT NULL");
    DB::update("UPDATE prc_process_attempts SET finished_at = '2000-01-01 00:00:00+00' WHERE work_id = ?", [$works[0]->value->value]);
    DB::delete("DELETE FROM prc_process_handler_holds WHERE stage = 'listing'");
    expect(fn () => app(RecoveryReconciler::class)->sweepHandlerFaults())->toThrow(LogicException::class)
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_policy_checked_at')->count())->toBe(0);
    app(ProcessAuthoritySync::class)->registerHandler(StageCode::Listing, 'fixture-listing/1');
    expect(app(RecoveryReconciler::class)->sweepHandlerFaults())->toBe(3)
        ->and(faultHold()->state)->toBe('held');
});

test('два input независимо исчерпывают budget: checkpoint, partial, dependency и pause не сбрасывают faults; четвёртая delivery не вызывает handler', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $works = [ExecutionFixtures::listingWork(), ExecutionFixtures::listingWork()];
    $finalizer = app(WorkFinalizer::class);
    foreach ($works as $work) {
        $finalizer->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault('first'));
        $lease = FinalizeFixtures::rerun($work);
        expect(app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(1), 0)->status->value)->toBe('saved');
        $finalizer->finish($lease, new StageDeferred(DeferralReason::StorageUnavailable, new RetryAt(new DateTimeImmutable('+30 seconds'))));
        ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
        ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
        $finalizer->finish(FinalizeFixtures::rerun($work), new StagePartial(new FixtureStageCheckpoint(2), new MissingParts(['remaining'])));
        $finalizer->finishHandlerFault(FinalizeFixtures::running($work), FinalizeFixtures::fault('second'));
        $finalizer->finishHandlerFault(FinalizeFixtures::rerun($work), FinalizeFixtures::fault('third'));
        $row = DB::table('prc_process_work')->where('id', $work->value->value)->sole();
        expect([$row->state, $row->reason_code, $row->item_failures, $row->due_at])->toBe(['blocked', 'handler_fault_limit', 0, null])
            ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNotNull('fault_signature')->count())->toBe(3);
    }
    // Distinct-input global hold remains separate: two inputs never reach the threshold of three.
    expect(app(RecoveryReconciler::class)->sweepHandlerFaults())->toBe(6)
        ->and(faultHold()->state)->toBe('healthy');
    $handler = new class implements StageHandler
    {
        public int $calls = 0;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $this->calls++;

            return FinalizeFixtures::succeeded(StageCode::Listing, 'unexpected-fourth');
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);
    $attempts = DB::table('prc_process_attempts')->count();
    foreach ($works as $work) {
        $delivery = DeliveryEnvelope::fromScalars($work->value->value, ExecutionFixtures::delivery($work)->value, LedgerFixtures::uuid()->value, 1);
        expect(app(WorkRunner::class)->runDelivery($delivery))->toBe(DeliveryOutcome::Rejected);
    }
    expect($handler->calls)->toBe(0)
        ->and(DB::table('prc_process_attempts')->count())->toBe($attempts)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
});
