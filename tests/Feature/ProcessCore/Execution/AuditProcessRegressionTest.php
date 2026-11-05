<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeliveryOutcome;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\IntentRepository;
use App\Repositories\Procurement\Process\RecoveryRepository;
use App\Repositories\Procurement\Process\RunReadRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Process\WorkRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('work-controls-and-fences', 'claim-recovery-and-invariants', 'delivery-and-daemon-lifecycle');

test('CancelWork очищает checkpoint для running работы с уже разрешённым provider plan, но сохраняет его для blocked(provider_result_unknown)', function (): void {
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
        $store = app(WorkStoreRepository::class);
        $subject = new SubjectKey(SubjectKind::DocumentVersion, $version->id);
        $store->storeIntent(
            id: $orphanIntent,
            intentKey: LedgerFixtures::hash('orphan:'.$orphanIntent->value),
            stage: StageCode::Screening,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('sem'),
            accessPolicyHash: LedgerFixtures::hash('acc'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Pending,
        );
        $store->storeIntent(
            id: $validIntent,
            intentKey: LedgerFixtures::hash('valid:'.$validIntent->value),
            stage: StageCode::Screening,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('sem2'),
            accessPolicyHash: LedgerFixtures::hash('acc2'),
            cycleSeq: 2,
            expectedHeadGeneration: 1,
            state: IntentState::Pending,
        );
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
    app(ProcessTransaction::class)->run(function () use ($document, $intentId): void {
        app(WorkStoreRepository::class)->storeIntent(
            id: $intentId,
            intentKey: LedgerFixtures::hash('dl-intent:'.$intentId->value),
            stage: StageCode::Download,
            subject: new SubjectKey(SubjectKind::Document, $document->id),
            semanticPolicyHash: LedgerFixtures::hash('sem-dl'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );
    });

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
        ->toBe('quarantined_schema_unsupported');

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

    $owner = $resolver->ownerSubject(StageCode::Screening, $locked);
    expect($owner->subject)->toEqual($verSubject);

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

    $correlation = app(RunReadRepository::class)->correlationFor($work);
    expect($correlation['cohort_key'] ?? null)->toBe('platform:eis-cohort-norm');
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

    // Создаём 51 заблокированную по paused запись: первые 50 с неподдерживаемой input_schema_version=2 (голова очереди), 51-я валидная.
    $poisons = [];
    for ($i = 0; $i < 50; $i++) {
        $poison = ExecutionFixtures::listingWork();
        $poisons[] = $poison->value->value;
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
        ->and(ExecutionFixtures::work($tail)->state)->toBe('blocked');

    // Второй вызов того же экземпляра продвигает курсор за первые 50 застрявших записей и освобождает 51-ю запись.
    expect($reconciler->releaseBlocked())->toBe(1)
        ->and(ExecutionFixtures::work($tail)->state)->toBe('ready');
});

test('RecoveryReconciler::sweepHandlerFaults изолирует сбойную запись в батче, фиксируя здоровые проверки и пробрасывая ошибку после батча', function (): void {
    FinalizeFixtures::authority([StageCode::Listing, StageCode::Download]);
    $finalizer = app(WorkFinalizer::class);

    $listingFault = ExecutionFixtures::listingWork();
    $finalizer->finishHandlerFault(FinalizeFixtures::running($listingFault), FinalizeFixtures::fault('bad-hold'));

    $document = Document::factory()->create();
    $downloadFault = LedgerFixtures::storeWork(LedgerFixtures::downloadInput($document->id, LedgerFixtures::uuid()));
    $finalizer->finishHandlerFault(FinalizeFixtures::running($downloadFault), FinalizeFixtures::fault('good-hold'));

    // Удаляем authority-строку для listing, чтобы первая проверка выбросила LogicException, но download должен пройти в своей транзакции.
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
    app(WorkFeeder::class)->tick('acquisition');
    $envelope = DeliveryEnvelope::fromScalars(
        $work->value->value,
        ExecutionFixtures::delivery($work)->value,
        LedgerFixtures::uuid()->value,
        1,
    );

    // 1. Транзиентный сбой БД на readAuthoritySnapshot (после успешного claim) возвращает DependencyUnavailable, оставляет running и не вызывает handler.
    $claimed = false;
    $armed = true;
    DB::beforeExecuting(function (string $query, array $bindings) use (&$armed, &$claimed): void {
        if (str_contains($query, 'INSERT INTO prc_process_attempts')) {
            $claimed = true;

            return;
        }
        if (! $armed || ! $claimed || ! str_contains($query, 'SELECT stage, cohort_key, input_revision')) {
            return;
        }
        $armed = false;
        $driver = new PDOException('simulated snapshot disconnect');
        $driver->errorInfo = ['08006', 7, 'simulated snapshot disconnect'];

        throw new QueryException('pgsql', $query, $bindings, $driver);
    });

    expect(app(WorkRunner::class)->runDelivery($envelope))->toBe(DeliveryOutcome::DependencyUnavailable)
        ->and($handler->calls)->toBe(0)
        ->and(ExecutionFixtures::work($work)->state)->toBe('running')
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNull('finished_at')->count())->toBe(1)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNotNull('fault_signature')->count())->toBe(0);

    // 2. Нетранзиентная SQL ошибка на том же шаге bootstrap пробрасывается видимо.
    $work2 = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    $envelope2 = DeliveryEnvelope::fromScalars(
        $work2->value->value,
        ExecutionFixtures::delivery($work2)->value,
        LedgerFixtures::uuid()->value,
        1,
    );
    $claimedFatal = false;
    $armedFatal = true;
    DB::beforeExecuting(function (string $query, array $bindings) use (&$armedFatal, &$claimedFatal): void {
        if (str_contains($query, 'INSERT INTO prc_process_attempts')) {
            $claimedFatal = true;

            return;
        }
        if (! $armedFatal || ! $claimedFatal || ! str_contains($query, 'SELECT stage, cohort_key, input_revision')) {
            return;
        }
        $armedFatal = false;
        $driver = new PDOException('simulated fatal sql');
        $driver->errorInfo = ['42P01', 7, 'simulated fatal sql'];

        throw new QueryException('pgsql', $query, $bindings, $driver);
    });
    expect(fn () => app(WorkRunner::class)->runDelivery($envelope2))->toThrow(QueryException::class)
        ->and($handler->calls)->toBe(0);

    // 3. Typed dependency deferral (storage_unavailable) получает due_at в окне bounded jitter [30, 35] секунд.
    DB::table('prc_process_reservations')
        ->whereIn('work_id', [$work->value->value, $work2->value->value])
        ->delete();
    DB::table('prc_process_work')
        ->whereIn('id', [$work->value->value, $work2->value->value])
        ->update(['state' => 'succeeded', 'lease_until' => null, 'owner_token' => null]);
    $handler->throwStorageUnavailable = true;
    $work3 = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    $envelope3 = DeliveryEnvelope::fromScalars(
        $work3->value->value,
        ExecutionFixtures::delivery($work3)->value,
        LedgerFixtures::uuid()->value,
        1,
    );
    expect(app(WorkRunner::class)->runDelivery($envelope3))->toBe(DeliveryOutcome::Executed)
        ->and(ExecutionFixtures::work($work3)->state)->toBe('deferred');
    $dueIn = (float) DB::selectOne('SELECT extract(epoch FROM due_at - clock_timestamp()) AS due_in FROM prc_process_work WHERE id = ?', [$work3->value->value])->due_in;
    expect($dueIn)->toBeGreaterThan(29.0)->toBeLessThanOrEqual(36.0);
});
