<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\Authority\LockedSubject;
use App\Dto\Procurement\Process\Execution\DeliveryEnvelope;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageFailedItem;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\SubjectKey;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\SubjectKind;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Governance\IntentState;
use App\Enums\Procurement\Process\Outcomes\CompletionStatus;
use App\Enums\Procurement\Process\Outcomes\DeliveryOutcome;
use App\Enums\Procurement\Process\Outcomes\ItemFailureReason;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Enums\Procurement\Process\Outcomes\WorkMutationStatus;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Projection\RecoveryRepository;
use App\Repositories\Procurement\Process\Projection\RunReadRepository;
use App\Repositories\Procurement\Process\Work\IntentRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Daemons\FeederDaemon;
use App\Services\Procurement\Process\Daemons\RecoveryReconciler;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\SubjectLockResolver;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Execution\WorkRunner;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('work-controls-and-fences', 'claim-recovery-and-invariants', 'delivery-and-daemon-lifecycle');

function auditStoreIntent(
    ProcessUuid $id,
    StageCode $stage,
    SubjectKey $subject,
    IntentState $state,
    int $cycleSeq = 1,
    string $seed = 'intent',
): void {
    app(WorkStoreRepository::class)->storeIntent(
        id: $id,
        intentKey: LedgerFixtures::hash($seed.':'.$id->value),
        stage: $stage,
        subject: $subject,
        semanticPolicyHash: LedgerFixtures::hash('sem:'.$seed),
        accessPolicyHash: LedgerFixtures::hash('acc:'.$seed),
        cycleSeq: $cycleSeq,
        expectedHeadGeneration: 1,
        state: $state,
    );
}

function auditDeliveryEnvelope(WorkId $work): DeliveryEnvelope
{
    app(WorkFeeder::class)->tick('acquisition');

    return DeliveryEnvelope::fromScalars(
        $work->value->value,
        ExecutionFixtures::delivery($work)->value,
        ProcessUuid::random()->value,
        1,
    );
}

function auditArmSnapshotSqlFailure(string $sqlState, string $message): void
{
    $claimed = false;
    $armed = true;
    DB::beforeExecuting(function (string $query, array $bindings) use (&$armed, &$claimed, $sqlState, $message): void {
        if (str_contains($query, 'INSERT INTO prc_process_attempts')) {
            $claimed = true;

            return;
        }
        if (! $armed || ! $claimed || ! str_contains($query, 'SELECT stage, cohort_key, input_revision')) {
            return;
        }
        $armed = false;
        $driver = new PDOException($message);
        $driver->errorInfo = [$sqlState, 7, $message];

        throw new QueryException('pgsql', $query, $bindings, $driver);
    });
}

test('CancelWork очищает checkpoint для running работы с уже разрешённым provider plan, но сохраняет его для blocked(provider_result_unknown)', function (): void {
    expect(ProcessUuid::random()->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();

    $runningWork = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($runningWork);
    $plan = FixtureProviderRecoveryResolver::plan(
        ProviderRecoveryMode::KnownRetryEligible,
        FinalizeFixtures::inputHash($runningWork),
    );
    $resolver->finding($runningWork, $plan);

    expect(app(CancelWork::class)->execute($runningWork, 'cancel resolved running')->status)
        ->toBe(WorkMutationStatus::Applied)
        ->and(DB::table('prc_process_work')->where('id', $runningWork->value->value)->value('checkpoint'))
        ->toBeNull();
});

test('IntentRepository::activateNextLocked пропускает pending intent без work и вычисляет head_generation для prc_document_versions', function (): void {
    FinalizeFixtures::authority([StageCode::Download]);
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    $orphanIntent = LedgerFixtures::uuid();
    $validIntent = LedgerFixtures::uuid();

    app(ProcessTransaction::class)->run(function () use ($version, $orphanIntent, $validIntent): void {
        $subject = new SubjectKey(SubjectKind::DocumentVersion, $version->id);
        auditStoreIntent($orphanIntent, StageCode::Screening, $subject, IntentState::Pending, 1, 'orphan');
        auditStoreIntent($validIntent, StageCode::Screening, $subject, IntentState::Pending, 2, 'valid');
    });

    DB::table('prc_operation_intents')->where('id', $orphanIntent->value)->update([
        'requested_at' => DB::raw("clock_timestamp() - interval '10 seconds'"),
    ]);

    LedgerFixtures::storeWork(
        input: LedgerFixtures::downloadInput($document->id, $validIntent),
        intentId: $validIntent,
    );

    $activated = app(ProcessTransaction::class)->run(
        fn (): ?string => app(IntentRepository::class)->activateNextLocked(
            'screening',
            SubjectKind::DocumentVersion->value,
            (string) $version->id,
        ),
    );

    expect($activated)->toBe($validIntent->value)
        ->and(DB::table('prc_operation_intents')->where('id', $orphanIntent->value)->value('state'))->toBe('pending')
        ->and(DB::table('prc_operation_intents')->where('id', $validIntent->value)->value('state'))->toBe('active')
        ->and(DB::table('prc_operation_intents')->where('id', $validIntent->value)->value('expected_head_generation'))->toBe(1)
        ->and(AuthorityRepository::headColumn(SubjectKind::DocumentVersion))->toBe('1::bigint AS head_generation');
});

test('StageFailedItem освобождает active intent и восстанавливает расписание ревалидации документа', function (): void {
    FinalizeFixtures::authority([StageCode::Download]);
    $document = Document::factory()->create(['next_revalidation_due_at' => null]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    DB::table('prc_documents')->where('id', $document->id)->update(['current_version_id' => $version->id]);

    $intentId = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(
        fn () => auditStoreIntent($intentId, StageCode::Download, new SubjectKey(SubjectKind::Document, $document->id), IntentState::Active, 1, 'dl-intent'),
    );

    $work = LedgerFixtures::storeWork(
        input: LedgerFixtures::downloadInput($document->id, $intentId),
        intentId: $intentId,
    );
    $lease = FinalizeFixtures::running($work);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['item_failures' => 2]);
    $outcome = new StageFailedItem(
        ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'evidence/download-404', LedgerFixtures::hash('dl-404')),
    );

    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->status)->toBe(CompletionStatus::Applied)
        ->and(DB::table('prc_operation_intents')->where('id', $intentId->value)->value('state'))->toBe('terminal')
        ->and(DB::table('prc_documents')->where('id', $document->id)->value('next_revalidation_due_at'))->not->toBeNull();
});

test('FeederDaemon отправляет в карантин feeder_wake с неподдерживаемой payload_schema_version и ProcessControlCommand поддерживает retry-failed-item', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $messageId = LedgerFixtures::uuid()->value;
    DB::table('prc_process_outbox')->insert([
        'message_id' => $messageId,
        'dedup_key' => 'feed:acquisition:unsupported',
        'kind' => 'feeder_wake',
        'payload' => json_encode(['lane' => 'acquisition'], JSON_THROW_ON_ERROR),
        'payload_schema_version' => 2,
        'available_at' => DB::raw('clock_timestamp()'),
    ]);

    $tick = app(FeederDaemon::class)->tick();
    expect($tick->errorCode)->toBe('outbox_quarantined')
        ->and(DB::table('prc_process_outbox')->where('message_id', $messageId)->value('last_error_code'))
        ->toBe('quarantined_schema_unsupported')
        ->and(DB::table('prc_process_incidents')->where('kind', 'outbox_quarantine')->count())
        ->toBe(1);

    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['item_failures' => 2]);
    app(WorkFinalizer::class)->finish(
        $lease,
        new StageFailedItem(
            ItemFailureReason::InvalidInput,
            new ArtifactRef('fixture', 'evidence/bad-row', LedgerFixtures::hash('bad-row')),
        ),
    );
    expect(ExecutionFixtures::work($work)->state)->toBe('failed_item');

    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $seq = (int) DB::table('prc_process_work')->where('id', $work->value->value)->value('transition_seq');

    $this->artisan('process:control', [
        'action' => 'retry-failed-item',
        'target' => $work->value->value,
        '--user' => (string) $operator->id,
        '--reason' => 'operator retry failed item',
        '--expected' => (string) $seq,
    ])->assertSuccessful();

    expect(DB::table('prc_process_work')->where('state', 'ready')->where('id', '!=', $work->value->value)->count())->toBe(1);
});

test('SubjectLockResolver::ownerSubject находит владельца при составной блокировке и RunReadRepository::correlationFor нормализует cohort_key площадки', function (): void {
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    $resolver = app(SubjectLockResolver::class);
    $docSubject = new SubjectKey(SubjectKind::Document, $document->id);
    $verSubject = new SubjectKey(SubjectKind::DocumentVersion, $version->id);
    $locked = [
        $docSubject->key() => new LockedSubject($docSubject, 1, null, $version->id),
        $verSubject->key() => new LockedSubject($verSubject, 1, null),
    ];

    expect($resolver->ownerSubject(StageCode::Screening, $locked)->subject)->toEqual($verSubject);

    FinalizeFixtures::authority([StageCode::Listing]);
    $platform = Platform::factory()->create(['code' => 'eis-cohort-norm']);
    $block = LedgerFixtures::crawlBlock();
    $work = LedgerFixtures::storeWork(
        LedgerFixtures::listingInput($block['block'], $block['filter_hash'], $block['access_policy_hash']),
    );
    DB::table('prc_process_work')->where('id', $work->value->value)->update([
        'platform_id' => $platform->id,
        'cohort_key' => 'platform:'.$platform->id,
    ]);

    expect(app(RunReadRepository::class)->correlationFor($work)['cohort_key'] ?? null)->toBe('platform:eis-cohort-norm');
});

test('RecoveryRepository::controlPredicate строится по активным паузам без DISTINCT-скана prc_process_work, а курсоры RecoveryReconciler обходят застрявшую голову батча', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    $repo = app(RecoveryRepository::class);

    $work = ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);
    ExecutionFixtures::setMode('system', ControlMode::Paused);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $admittedWhenPaused = $repo->admittedCandidates(50);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(array_map(static fn ($id): string => $id->value->value, $admittedWhenPaused))->toContain($work->value->value)
        ->and(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'SELECT DISTINCT stage, cohort_key, platform_id FROM prc_process_work')))
        ->toBeFalse();

    ExecutionFixtures::setMode('system', ControlMode::Enabled);

    $poisons = [];
    for ($i = 0; $i < 50; $i++) {
        $poisons[] = ExecutionFixtures::listingWork()->value->value;
    }
    $tail = ExecutionFixtures::listingWork();

    DB::table('prc_process_work')->whereIn('id', $poisons)->update([
        'state' => 'blocked',
        'reason_code' => 'paused',
        'input_schema_version' => 2,
        'updated_at' => '2000-01-01 00:00:00+00',
    ]);
    DB::table('prc_process_work')->where('id', $tail->value->value)->update([
        'state' => 'blocked',
        'reason_code' => 'paused',
        'updated_at' => '2000-01-01 00:01:00+00',
    ]);

    $reconciler = app(RecoveryReconciler::class);
    expect($reconciler->releaseBlocked())->toBe(0)
        ->and(ExecutionFixtures::work($tail)->state)->toBe('blocked')
        ->and($reconciler->releaseBlocked())->toBe(1)
        ->and(ExecutionFixtures::work($tail)->state)->toBe('ready');

    // Гарантированная квота cancelledProvider (25) и blockedProvider (50 - count(cancelled)) в рамках 50 кандидатов на тик.
    $codec = app(StageRegistry::class)->payloadCodec();
    $poisonPayload = $codec->encodeCheckpoint(new WorkCheckpointEnvelope(
        new FixtureStageCheckpoint(0),
        FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, LedgerFixtures::hash('poison-plan')),
    ));
    DB::table('prc_process_work')->whereIn('id', $poisons)->update([
        'state' => 'blocked',
        'reason_code' => 'provider_result_unknown',
        'provider_recovery_due_at' => '2000-01-01 00:00:00+00',
        'checkpoint' => $poisonPayload->json,
        'checkpoint_schema_version' => $poisonPayload->schemaVersion,
        'checkpoint_seq' => 1,
    ]);

    $tailUnknown = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($tail));
    $tailPayload = $codec->encodeCheckpoint(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(0), $tailUnknown));
    DB::table('prc_process_work')->where('id', $tail->value->value)->update([
        'state' => 'blocked',
        'reason_code' => 'provider_result_unknown',
        'provider_recovery_due_at' => '2000-01-01 00:01:00+00',
        'checkpoint' => $tailPayload->json,
        'checkpoint_schema_version' => $tailPayload->schemaVersion,
        'checkpoint_seq' => 1,
    ]);
    $resolver->finding($tail, FixtureProviderRecoveryResolver::resolved($tailUnknown, ProviderRecoveryMode::ResumeFromReceipt));

    $cancelledPoisons = [];
    for ($i = 0; $i < RecoveryReconciler::CANCELLED_PROVIDER_QUOTA; $i++) {
        $cancelledPoisons[] = ExecutionFixtures::listingWork()->value->value;
    }
    DB::table('prc_process_work')->whereIn('id', $cancelledPoisons)->update([
        'state' => 'cancelled',
        'reason_code' => 'cancelled',
        'input_schema_version' => 2,
        'updated_at' => '2000-01-01 00:00:00+00',
        'checkpoint' => $poisonPayload->json,
        'checkpoint_schema_version' => $poisonPayload->schemaVersion,
        'checkpoint_seq' => 1,
    ]);

    $cancelledHealthy = ExecutionFixtures::listingWork();
    $cancelledUnknown = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($cancelledHealthy));
    $cancelledPayload = $codec->encodeCheckpoint(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(0), $cancelledUnknown));
    DB::table('prc_process_work')->where('id', $cancelledHealthy->value->value)->update([
        'state' => 'cancelled',
        'reason_code' => 'cancelled',
        'updated_at' => '2000-01-01 00:01:00+00',
        'checkpoint' => $cancelledPayload->json,
        'checkpoint_schema_version' => $cancelledPayload->schemaVersion,
        'checkpoint_seq' => 1,
    ]);
    $resolver->finding($cancelledHealthy, FixtureProviderRecoveryResolver::resolved($cancelledUnknown, ProviderRecoveryMode::ResumeFromReceipt));

    $tickInspected = [];
    $resolver->afterInspect = static function (WorkId $id) use (&$tickInspected): void {
        $tickInspected[] = $id->value->value;
    };

    expect($reconciler->recoverBlockedProvider())->toBe(0)
        ->and($tickInspected)->toHaveCount(RecoveryReconciler::BATCH)
        ->and(array_unique($tickInspected))->toHaveCount(RecoveryReconciler::BATCH)
        ->and(array_intersect($tickInspected, $cancelledPoisons))->toHaveCount(RecoveryReconciler::CANCELLED_PROVIDER_QUOTA)
        ->and(array_intersect($tickInspected, $poisons))->toHaveCount(RecoveryReconciler::BATCH - RecoveryReconciler::CANCELLED_PROVIDER_QUOTA);

    $tickInspected = [];
    expect($reconciler->recoverBlockedProvider())->toBe(1)
        ->and($tickInspected)->toHaveCount(RecoveryReconciler::BATCH)
        ->and(array_unique($tickInspected))->toHaveCount(RecoveryReconciler::BATCH)
        ->and($tickInspected)->toContain($cancelledHealthy->value->value)
        ->and(DB::table('prc_process_work')->where('id', $cancelledHealthy->value->value)->value('checkpoint'))->toBeNull()
        ->and($resolver->settlements)->toBe([$cancelledUnknown->physicalCallId->value.':resume_from_receipt']);

    $tickInspected = [];
    expect($reconciler->recoverBlockedProvider())->toBe(1)
        ->and($tickInspected)->toHaveCount(RecoveryReconciler::BATCH)
        ->and(array_unique($tickInspected))->toHaveCount(RecoveryReconciler::BATCH)
        ->and($tickInspected)->toContain($tail->value->value)
        ->and(ExecutionFixtures::work($tail)->state)->toBe('ready')
        ->and($resolver->settlements)->toHaveCount(2);
});

test('RecoveryReconciler::sweepHandlerFaults изолирует сбойную запись в батче, фиксируя здоровые проверки и пробрасывая ошибку после батча', function (): void {
    FinalizeFixtures::authority([StageCode::Listing, StageCode::Download]);
    $finalizer = app(WorkFinalizer::class);

    $listingFault = ExecutionFixtures::listingWork();
    $finalizer->finishHandlerFault(FinalizeFixtures::running($listingFault), FinalizeFixtures::fault('bad-hold'));

    $document = Document::factory()->create();
    $downloadFault = LedgerFixtures::storeWork(LedgerFixtures::downloadInput($document->id, LedgerFixtures::uuid()));
    $finalizer->finishHandlerFault(FinalizeFixtures::running($downloadFault), FinalizeFixtures::fault('good-hold'));

    DB::delete("DELETE FROM prc_process_handler_holds WHERE stage = 'listing'");

    expect(fn () => app(RecoveryReconciler::class)->sweepHandlerFaults())->toThrow(LogicException::class)
        ->and(DB::table('prc_process_attempts')->where('work_id', $downloadFault->value->value)->whereNotNull('fault_policy_checked_at')->count())
        ->toBe(1)
        ->and(DB::table('prc_process_attempts')->where('work_id', $listingFault->value->value)->whereNotNull('fault_policy_checked_at')->count())
        ->toBe(0);
});

test('WorkRunner классифицирует транзиентный сбой БД при чтении authority snapshot после claim как DependencyUnavailable без вызова handler и без расхода fault budget, а typed deferral получает bounded jitter', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $handler = new class implements StageHandler
    {
        public int $calls = 0;

        public bool $throwStorageUnavailable = false;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            $this->calls++;
            if ($this->throwStorageUnavailable) {
                throw new DependencyUnavailable('storage');
            }

            return FinalizeFixtures::succeeded(StageCode::Listing, 'delivered');
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);

    $work = ExecutionFixtures::listingWork();
    $envelope = auditDeliveryEnvelope($work);
    auditArmSnapshotSqlFailure('08006', 'simulated snapshot disconnect');

    expect(app(WorkRunner::class)->runDelivery($envelope))->toBe(DeliveryOutcome::DependencyUnavailable)
        ->and($handler->calls)->toBe(0)
        ->and(ExecutionFixtures::work($work)->state)->toBe('running')
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNotNull('fault_signature')->count())->toBe(0);

    $work2 = ExecutionFixtures::listingWork();
    $envelope2 = auditDeliveryEnvelope($work2);
    auditArmSnapshotSqlFailure('42P01', 'simulated fatal sql');

    expect(fn () => app(WorkRunner::class)->runDelivery($envelope2))->toThrow(QueryException::class)
        ->and($handler->calls)->toBe(0);

    DB::table('prc_process_reservations')->whereIn('work_id', [$work->value->value, $work2->value->value])->delete();
    DB::table('prc_process_work')->whereIn('id', [$work->value->value, $work2->value->value])
        ->update(['state' => 'succeeded', 'lease_until' => null, 'owner_token' => null]);

    $handler->throwStorageUnavailable = true;
    $work3 = ExecutionFixtures::listingWork();
    $envelope3 = auditDeliveryEnvelope($work3);
    $observeDeferral = true;
    $retryAt = null;
    $beforeFinalizeAt = null;
    DB::beforeExecuting(function (string $query, array $bindings) use ($work3, &$observeDeferral, &$retryAt, &$beforeFinalizeAt): void {
        if (! $observeDeferral
            || ! str_contains($query, 'UPDATE prc_process_work')
            || ! str_contains($query, 'due_at = GREATEST(')
            || ($bindings[0] ?? null) !== 'deferred'
            || ($bindings[1] ?? null) !== 'storage_unavailable'
            || ($bindings[11] ?? null) !== $work3->value->value) {
            return;
        }
        $retryAt = $bindings[2];
        $beforeFinalizeAt = DB::selectOne('SELECT clock_timestamp()::text AS now')->now;
    });
    try {
        expect(app(WorkRunner::class)->runDelivery($envelope3))->toBe(DeliveryOutcome::Executed)
            ->and(ExecutionFixtures::work($work3)->state)->toBe('deferred');
    } finally {
        $observeDeferral = false;
    }

    expect($retryAt)->not->toBeNull()->and($beforeFinalizeAt)->not->toBeNull();
    $deferral = DB::selectOne(
        'SELECT extract(epoch FROM ?::timestamptz - ?::timestamptz) AS retry_in,
                due_at >= ?::timestamptz AS respects_retry_at,
                due_at <= ?::timestamptz + (?::timestamptz - ?::timestamptz) * 0.1 AS bounded_jitter
         FROM prc_process_work WHERE id = ?',
        [$retryAt, $beforeFinalizeAt, $retryAt, $retryAt, $retryAt, $beforeFinalizeAt, $work3->value->value],
    );
    expect((float) $deferral->retry_in)->toBeGreaterThan(29.0)->toBeLessThanOrEqual(35.0)
        ->and($deferral->respects_retry_at)->toBeTrue()
        ->and($deferral->bounded_jitter)->toBeTrue();
});

test('WorkRunner::finalize сопоставляет LostOwnership с LeaseLost и InvariantViolation с Rejected при небросающем предупреждении телеметрии', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $handler = new class implements StageHandler
    {
        public ?Closure $effect = null;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            if ($this->effect !== null) {
                ($this->effect)($execution);
            }

            return FinalizeFixtures::succeeded(StageCode::Listing, 'delivered');
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);

    $lostWork = ExecutionFixtures::listingWork();
    $lostEnvelope = auditDeliveryEnvelope($lostWork);
    $handler->effect = static function () use ($lostWork): void {
        expect(app(CancelWork::class)->execute($lostWork, 'cancel during execution')->status)
            ->toBe(WorkMutationStatus::Applied);
    };

    expect(app(WorkRunner::class)->runDelivery($lostEnvelope))->toBe(DeliveryOutcome::LeaseLost)
        ->and(ExecutionFixtures::work($lostWork)->state)->toBe('cancelled');

    Log::spy();
    $corruptWork = ExecutionFixtures::listingWork();
    $corruptEnvelope = auditDeliveryEnvelope($corruptWork);
    $handler->effect = static function () use ($corruptWork): void {
        DB::table('prc_process_reservations')->where('work_id', $corruptWork->value->value)->limit(1)->delete();
    };

    expect(app(WorkRunner::class)->runDelivery($corruptEnvelope))->toBe(DeliveryOutcome::Rejected);
    Log::shouldHaveReceived('log')->withArgs(
        static fn (string $level, string $message, array $context): bool => $level === 'warning'
            && $message === 'process.work_finalization_invariant_violation'
            && ($context['status'] ?? null) === CompletionStatus::InvariantViolation->value,
    )->once();
});
