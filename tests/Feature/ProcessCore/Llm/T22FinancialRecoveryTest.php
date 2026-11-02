<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptPolicy;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\ExtractionContract;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('p39', 't15', 't22', 't30');

beforeEach(function (): void {
    $this->period = FinancialFixtures::reset();
});

function p39Tick(): int
{
    $daemon = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $daemon->register($category);
    }
    $result = $daemon->tick();
    expect($result->errorCode)->toBeNull();

    return $result->processed;
}

test('authorization duplicate и crash до claim отзывают старый token как not_sent с нулевым settlement', function (): void {
    [$work, $lease, $window, $plan, $operation] = FinancialFixtures::authorized($this->period);
    expect(app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $this->period))->toEqual($operation)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1);
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('ready')
        ->and(app(RequestRepository::class)->get($operation->physicalCallId)->outcome)->toBe('not_sent')
        ->and(DB::table('llm_budget_periods')->where('id', $this->period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_reservations')->value('settled_amount'))->toBe('0.00000000')
        ->and(FinancialFixtures::claim($operation))->toBeFalse();
    Http::assertNothingSent();
});

test('claimed crash без bytes остаётся unknown и elapsed time не разрешает новый send', function (): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    expect(FinancialFixtures::claim($operation))->toBeTrue()->and(FinancialFixtures::claim($operation))->toBeFalse();
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe('provider_result_unknown')
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('reserved');
    $before = ExecutionFixtures::work($work)->transition_seq;
    FinancialFixtures::due($work);
    expect(p39Tick())->toBe(0)->and(ExecutionFixtures::work($work)->transition_seq)->toBe($before)
        ->and(DB::table('llm_provider_requests')->value('state'))->toBe('unknown')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1);
    Http::assertNothingSent();
});

test('EXR03 late put-before-ref после committed blocked восстанавливается periodic scan и restart без новой reservation', function (OperationPurpose $purpose, ProviderRecoveryMode $mode): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period, $purpose);
    FinancialFixtures::claim($operation);
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $call = app(RequestRepository::class)->get($operation->physicalCallId);
    app(ReceiptStore::class)->put($call, 200, FinancialFixtures::body());
    expect(DB::table('llm_provider_calls')->value('receipt_key'))->toBeNull();
    expect(fn () => app(RequestLedger::class)->replay($call, LedgerFixtures::uuid()->value, 'fixture-operator', 'must inspect orphan receipt'))
        ->toThrow(OperationDenied::class, 'replay_requires_missing_receipt');
    // A new resolver simulates restart: its only inputs are durable rows and the immutable object.
    app()->forgetInstance(RecoveryReconciler::class);
    FinancialFixtures::due($work);
    expect(p39Tick())->toBe(1)->and(ExecutionFixtures::work($work)->state)->toBe('ready')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->value('settled_amount'))->toBe('0.00200001');
    $lease = FinalizeFixtures::running($work);
    $plan = $lease->checkpoint?->providerRecoveryPlan;
    expect($plan)->toBeInstanceOf(ProviderRecoveryPlan::class)->and($plan->mode)->toBe($mode)
        ->and($plan->physicalCallId->value)->toBe($operation->physicalCallId);
    if ($purpose === OperationPurpose::Generate) {
        $receipt = app(ReceiptStore::class)->inspect(app(RequestRepository::class)->get($operation->physicalCallId));
        $raw = json_decode($receipt->body, true, flags: JSON_THROW_ON_ERROR)['raw'];
        $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
        expect(app(StructuredTaskValidator::class)->validate($task, $raw, 'STOP')->disposition)
            ->toBe(StructuredDisposition::Accepted);
    }
    expect(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Screening, 'receipt-resumed'))->status)->toBe(CompletionStatus::Applied)
        ->and(DB::table('p39_receipt_publications')->count())->toBe(1)->and(p39Tick())->toBe(0);
    Http::assertNothingSent();
})->with([
    [OperationPurpose::Generate, ProviderRecoveryMode::ResumeFromReceipt],
    [OperationPurpose::CacheCreate, ProviderRecoveryMode::ResumeProviderAsset],
    [OperationPurpose::CacheRefresh, ProviderRecoveryMode::ResumeProviderAsset],
    [OperationPurpose::CacheStorage, ProviderRecoveryMode::ResumeProviderAsset],
    [OperationPurpose::FileUpload, ProviderRecoveryMode::ResumeProviderAsset],
    [OperationPurpose::Lookup, ProviderRecoveryMode::ResumeProviderAsset],
]);

test('late raw после cancel сохраняет financial facts без business publication', function (): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    $row = ExecutionFixtures::work($work);
    app(CancelWork::class)->execute($work, 'fixture cancellation', $row->transition_seq);
    $call = app(RequestRepository::class)->get($operation->physicalCallId);
    app(RequestLedger::class)->capture($call, 200, FinancialFixtures::body());
    expect(ExecutionFixtures::work($work)->state)->toBe('cancelled')
        ->and(DB::table('llm_budget_reservations')->value('settled_amount'))->toBe('0.00200001')
        ->and(DB::table('p39_receipt_publications')->count())->toBe(0)
        ->and(DB::table('prc_analysis_chunks')->count())->toBe(0)->and(DB::table('prc_estimates')->count())->toBe(0);
    Http::assertNothingSent();
});

test('verified error без proof не generation resume а known nonbillable выбирает retry', function (string $outcome, ?string $cost, string $state, string $mode): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 404, FinancialFixtures::body($outcome, $cost));
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe($state);
    $payload = json_decode(ExecutionFixtures::work($work)->checkpoint, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['provider_recovery_plan']['mode'])->toBe($mode);
})->with([
    ['unknown', null, 'blocked', 'blocked_unknown'],
    ['known_nonbillable_cache_miss', '0', 'ready', 'known_retry_eligible'],
    ['billed_error', '0.00200001', 'blocked', 'blocked_unknown'],
]);

test('pause сохраняет оплаченный receipt pinned и не делает work eligible до resume', function (): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    ExecutionFixtures::setMode('documents', ControlMode::Paused);
    app(ReceiptStore::class)->put(app(RequestRepository::class)->get($operation->physicalCallId), 200, FinancialFixtures::body());
    FinancialFixtures::due($work);
    expect(p39Tick())->toBe(1)->and(ExecutionFixtures::work($work)->reason_code)->toBe('paused')
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled');
    ExecutionFixtures::setMode('documents', ControlMode::Enabled);
    expect(app(RecoveryReconciler::class)->releaseBlocked())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint->providerRecoveryPlan->physicalCallId->value)->toBe($operation->physicalCallId);
});

test('storage unavailable partial object или чужой hash не снимает unknown reserve', function (string $fault): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    $call = app(RequestRepository::class)->get($operation->physicalCallId);
    if ($fault === 'partial') {
        Storage::disk('local')->put($call->receiptUploadKey, '{partial');
    } elseif ($fault === 'corrupt') {
        app(RequestLedger::class)->capture($call, 200, FinancialFixtures::body());
        Storage::disk('local')->put($call->receiptUploadKey, '{}');
    } else {
        Storage::shouldReceive('disk')->with('local')->andThrow(new RuntimeException('fixture storage down'));
    }
    FinancialFixtures::due($work);
    expect(p39Tick())->toBe(0)->and(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
    if ($fault !== 'corrupt') {
        expect(DB::table('llm_budget_reservations')->value('state'))->toBe('reserved');
    }
})->with(['partial', 'corrupt', 'unavailable']);

test('known not_sent retry сохраняет logical identity но создаёт новый physical reserve и consumes старый directive', function (): void {
    [$work, , $window, $plan, $first] = FinancialFixtures::authorized($this->period);
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    $lease = FinalizeFixtures::running($work);
    $second = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $this->period);
    DB::transaction(fn () => app(RequestRepository::class)->interruptLocked(app(RequestRepository::class)->get($first->physicalCallId)));
    expect(DB::table('llm_provider_requests')->value('state'))->toBe('sending');
    expect($second->logicalRequestId)->toBe($first->logicalRequestId)->and($second->physicalCallId)->not->toBe($first->physicalCallId)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(2)
        ->and(DB::table('llm_budget_reservations')->where('id', $first->reservationId)->value('settled_amount'))->toBe('0.00000000')
        ->and(FinancialFixtures::claim($first))->toBeFalse()->and(FinancialFixtures::claim($second))->toBeTrue();
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)->and(ExecutionFixtures::work($work)->reason_code)->toBe('provider_result_unknown');
    $checkpoint = json_decode(ExecutionFixtures::work($work)->checkpoint, true, flags: JSON_THROW_ON_ERROR);
    expect($checkpoint['provider_recovery_plan']['physical_call_id'])->toBe($second->physicalCallId);
});

test('EXR03 более 50 real mappings не голодают при missing receipt у первых кандидатов', function (): void {
    app(FinancialReservationRepository::class)->configureAccount('fixture-account', 'USD', 100, null, 'fixture-operator', 'explicit batch exposure for fairness test');
    $works = [];
    $operations = [];
    for ($n = 0; $n < 55; $n++) {
        [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
        FinancialFixtures::claim($operation);
        FinalizeFixtures::expire($work, 600);
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
        $works[] = $work;
        $operations[] = $operation;
    }
    foreach ($works as $n => $work) {
        DB::update("UPDATE prc_process_work SET provider_recovery_due_at=clock_timestamp()-interval '120 seconds'+make_interval(secs=>?) WHERE id=?", [$n, $work->value->value]);
    }
    $last = $operations[54];
    app(ReceiptStore::class)->put(app(RequestRepository::class)->get($last->physicalCallId), 200, FinancialFixtures::body());
    expect(p39Tick())->toBe(0)
        ->and(DB::table('prc_process_work')->where('provider_recovery_due_at', '<=', DB::raw('clock_timestamp()'))->count())->toBe(5);
    app()->forgetInstance(RecoveryReconciler::class);
    expect(p39Tick())->toBe(1)->and(ExecutionFixtures::work($works[54])->state)->toBe('ready')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(55)
        ->and(DB::table('llm_budget_reservations')->where('state', 'settled')->count())->toBe(1)
        ->and(DB::table('llm_provider_calls')->count())->toBe(55);
});

test('known nonbillable cache404 допускает ровно один новый uncached physical call с прежним logical key', function (): void {
    [$work, , $window, $plan, $first] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($first);
    app(RequestLedger::class)->capture(app(RequestRepository::class)->get($first->physicalCallId), 404, FinancialFixtures::body('known_nonbillable_cache_miss', '0'));
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    $second = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $this->period);
    expect($second->logicalRequestId)->toBe($first->logicalRequestId)->and($second->physicalCallId)->not->toBe($first->physicalCallId);
    FinancialFixtures::claim($second);
    app(RequestLedger::class)->capture(app(RequestRepository::class)->get($second->physicalCallId), 200, FinancialFixtures::body());
    expect(DB::table('llm_budget_reservations')->count())->toBe(2)
        ->and(DB::table('llm_budget_reservations')->where('id', $first->reservationId)->value('settled_amount'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_reservations')->where('id', $second->reservationId)->value('settled_amount'))->toBe('0.00200001')
        ->and(DB::table('llm_provider_requests')->count())->toBe(1);
    Http::assertNothingSent();
});

test('missing exact mapping открывает invariant incident и переносит scan due без нового send', function (): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    // Deliberate corruption: pinned envelope remains, its active mapping has disappeared.
    DB::update('UPDATE prc_analysis_llm_requests SET recovery_active=false WHERE work_id=?', [$work->value->value]);
    FinancialFixtures::due($work);
    expect(p39Tick())->toBe(0)->and(ExecutionFixtures::work($work)->state)->toBe('blocked')
        ->and(DB::table('prc_process_incidents')->where('kind', 'provider_unknown_linkage_missing')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('reserved');
});

test('input head drift после blocked разрешает только late financial settlement и superseded без публикации', function (): void {
    [$work, , $window, , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    DB::update('UPDATE prc_documents SET head_generation=head_generation+1 WHERE id=(SELECT document_id FROM prc_document_versions WHERE id=?)', [$window->run->documentVersionId]);
    app(ReceiptStore::class)->put(app(RequestRepository::class)->get($operation->physicalCallId), 200, FinancialFixtures::body());
    FinancialFixtures::due($work);
    expect(p39Tick())->toBe(1)->and(ExecutionFixtures::work($work)->state)->toBe('superseded')
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('settled')
        ->and(DB::table('p39_receipt_publications')->count())->toBe(0);
});

test('классификация verified receipt pinned и новая policy не превращает unknown charge в generation resume', function (): void {
    [$work, , , , $operation] = FinancialFixtures::authorized($this->period);
    FinancialFixtures::claim($operation);
    app(RequestLedger::class)->capture(app(RequestRepository::class)->get($operation->physicalCallId), 503, FinancialFixtures::body('unknown', null));
    app(ReceiptPolicies::class)->register('fixture-provider', new class implements ReceiptPolicy
    {
        public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment
        {
            return new ReceiptAssessment(ReceiptOutcome::Success, '0', 'changed-fixture-policy');
        }
    });
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($work)->reason_code)->toBe('provider_result_unknown')
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('reserved');
});

test('SQL serialization retry сохраняет provider IDs и mapping выделенные до повторяемой транзакции', function (): void {
    [$work, , $window, $plan] = FinancialFixtures::authorized($this->period);
    FinalizeFixtures::expire($work, 600);
    app(RecoveryReconciler::class)->recoverExpiredRunning();
    $lease = FinalizeFixtures::running($work);
    DB::unprepared(<<<'SQL'
        CREATE TEMP SEQUENCE p39_auth_retry;
        CREATE FUNCTION pg_temp.p39_auth_retry() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF nextval('pg_temp.p39_auth_retry')=1 THEN
                RAISE EXCEPTION 'fixture serialization failure' USING ERRCODE='40001';
            END IF;
            RETURN NEW;
        END $$;
        CREATE TRIGGER p39_auth_retry AFTER INSERT ON llm_provider_calls FOR EACH ROW EXECUTE FUNCTION pg_temp.p39_auth_retry();
        SQL);
    $generated = [];
    Str::createUuidsUsing(function () use (&$generated) {
        $id = Uuid::uuid4();
        $generated[] = (string) $id;

        return $id;
    });
    try {
        $operation = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $this->period);
        expect($generated)->toHaveCount(6)
            ->and((int) DB::selectOne('SELECT last_value FROM pg_temp.p39_auth_retry')->last_value)->toBe(2)
            ->and($operation->physicalCallId)->toBe($generated[1])
            ->and($operation->reservationId)->toBe($generated[2])
            ->and($operation->sendAuthorizationToken)->toBe($generated[3])
            ->and(DB::table('llm_provider_calls')->where('physical_call_id', $operation->physicalCallId)->value('receipt_id'))->toBe($generated[4])
            ->and(DB::table('prc_analysis_llm_requests')->where('physical_call_id', $operation->physicalCallId)->value('id'))->toBe($generated[5]);
    } finally {
        Str::createUuidsNormally();
        DB::statement('DROP TRIGGER p39_auth_retry ON llm_provider_calls');
        DB::statement('DROP FUNCTION pg_temp.p39_auth_retry()');
        DB::statement('DROP SEQUENCE pg_temp.p39_auth_retry');
    }
});
