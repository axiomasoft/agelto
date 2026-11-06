<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Exceptions\Llm\BillingExportUnreadable;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Llm\ReceiptUnavailable;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Llm\Cache\AssetRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\BillingReconciler;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageReport;
use App\Services\Llm\Dto\UsageRow;
use App\Services\Llm\Dto\UsageSignature;
use App\Services\Llm\Execution\PreparedProviderTransport;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderSendGate;
use App\Services\Llm\Execution\ProviderTransportOutcome;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\SendClaimResult;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Providers\GeminiAssetClient;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\EstimatePublisher;
use App\Services\Procurement\Extraction\ExtractionContract;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\AssetFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProviderSendFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('analysis-execution', 'provider-financial-ledger');

/** @param list<UsageRow> $dailyRows */
function auditUsageSource(array $dailyRows = [], ?UsageRow $summaryRow = null, string $asOf = '2026-03-02'): UsageSource
{
    return new class($dailyRows, $summaryRow, $asOf) implements UsageSource
    {
        /** @param list<UsageRow> $dailyRows */
        public function __construct(
            private array $dailyRows,
            private ?UsageRow $summaryRow,
            private string $asOf,
        ) {}

        public function usage(UsageQuery $query): UsageReport
        {
            $rows = $query->byDay ? $this->dailyRows : ($this->summaryRow === null ? [] : [$this->summaryRow]);

            return new UsageReport(
                rows: $rows,
                signature: new UsageSignature('audit', 'audit', 0, null, null, CarbonImmutable::parse($this->asOf)),
                beyondHorizon: false,
            );
        }
    };
}

function auditCacheProviderFixtures(WireFixture $server): ProviderSendFixtures
{
    $f = new ProviderSendFixtures($server, operationFactory: function (ProviderOperationPlan $base, string $origin): array {
        $client = new GeminiAssetClient($origin);
        $caps = AssetFixtures::capabilities($base->context);
        $prefix = AssetFixtures::prefix($base);
        $plan = AssetFixtures::plan($base, OperationPurpose::CacheCreate, $prefix->key->hash);

        return [$plan, $client->createCache($plan, $caps, $prefix, 2048, 10, 1000, 3600)];
    });
    app(ReceiptPolicies::class)->register('fixture-provider', new AssetFixtures);
    $server->respond(
        parse_url($f->wire->endpoint, PHP_URL_PATH),
        json_encode(AssetFixtures::envelope('cache'), JSON_THROW_ON_ERROR),
        headers: ['Content-Type' => 'application/json'],
        method: 'POST',
    );

    return $f;
}

/** @return array{AuthorizedProviderOperation, string, string, string} */
function auditPrepareRaceReceipt(string $period, string $seed): array
{
    $requests = app(RequestRepository::class);
    $op = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan($seed), ProcessUuid::random()->value, 1, $period));
    FinancialFixtures::claim($op);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($op->physicalCallId)));
    $call = $requests->get($op->physicalCallId);
    expect(app(ReceiptStore::class)->inspect($call))->toBeNull();
    $verified = app(ReceiptStore::class)->put($call, 200, FinancialFixtures::body());

    return [
        $op,
        var_export(base64_encode(serialize($verified)), true),
        var_export($op->physicalCallId, true),
        var_export(ProcessUuid::random()->value, true),
    ];
}

test('ScreeningFinalizer переводит активный screening intent версии документа в terminal при публикации результата', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    $intentId = ProcessUuid::random();
    app(ProcessTransaction::class)->run(function () use ($window, $intentId): void {
        app(WorkStoreRepository::class)->storeIntent(
            id: $intentId,
            intentKey: LedgerFixtures::hash('scr-intent:'.$intentId->value),
            stage: StageCode::Screening,
            subject: new SubjectKey(SubjectKind::DocumentVersion, $window->run->documentVersionId),
            semanticPolicyHash: LedgerFixtures::hash('sem'),
            accessPolicyHash: LedgerFixtures::hash('acc'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );
    });

    ScreeningRuntimeFixtures::capture($lease, $window, $period, ScreeningRuntimeFixtures::raw());
    $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));
    app(WorkFinalizer::class)->finish($lease, $outcome);
    $lease = FinalizeFixtures::running($work);
    $outcome = app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease));

    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->state?->value)->toBe('succeeded')
        ->and(DB::table('prc_operation_intents')->where('id', $intentId->value)->value('state'))->toBe('terminal');
});

test('BillingReconciler считает микродоллары через bcmath без float-дрейфа и отвергает отрицательные или нечисловые суммы', function (): void {
    $reconciler = new BillingReconciler(auditUsageSource());
    $tmp = tempnam(sys_get_temp_dir(), 'billing_audit_');
    assert(is_string($tmp));
    $from = CarbonImmutable::parse('2026-03-01');
    $to = CarbonImmutable::parse('2026-03-02');

    try {
        file_put_contents($tmp, "date,cost,currency\n2026-03-01,19.99,USD\n");
        expect($reconciler->reconcile($from, $to, $tmp)->exportedTotalMicros)->toBe(19_990_000);

        foreach (["date,cost,currency\n2026-03-01,-0.01,USD\n", "date,cost,currency\n2026-03-01,not-a-number,USD\n"] as $csv) {
            file_put_contents($tmp, $csv);
            expect(fn () => $reconciler->reconcile($from, $to, $tmp))->toThrow(BillingExportUnreadable::class);
        }
    } finally {
        @unlink($tmp);
    }
});

test('ExtractionSemanticValidator округляет произведение quantity * unit_price по коммерческому правилу HALF_UP до 2 знаков, а EstimatePublisher блокирует публикацию без планов извлечения', function (): void {
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $validator = app(StructuredTaskValidator::class);
    $basePayload = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);

    $validateRow = static function (string $qty, string $unit, string $total) use ($validator, $task, $basePayload) {
        $payload = $basePayload;
        $payload['rows'][0]['row']['quantity'] = $qty;
        $payload['rows'][0]['row']['unit_price_curr'] = $unit;
        $payload['rows'][0]['row']['total_price_curr'] = $total;

        return $validator->validate($task, json_encode($payload, JSON_THROW_ON_ERROR), 'STOP');
    };

    expect($validateRow('1.5', '0.01', '0.02')->disposition)->toBe(StructuredDisposition::Accepted);

    $rejected = $validateRow('1.5', '0.01', '0.01');
    expect($rejected->disposition)->toBe(StructuredDisposition::InvalidSemantics)
        ->and($rejected->errors)->toContain('totals_arithmetic')
        ->and($validateRow('0.333333', '3.00', '1.00')->disposition)->toBe(StructuredDisposition::Accepted)
        ->and($validateRow('-1.5', '0.01', '-0.02')->disposition)->toBe(StructuredDisposition::Accepted);

    FinancialFixtures::reset();
    $window = AiFixtures::window(sealPlan: false);
    DB::table('prc_analysis_runs')->where('id', $window->run->id->value)->update([
        'declared_coverage' => json_encode(['estimates' => []], JSON_THROW_ON_ERROR),
        'declared_coverage_schema_version' => 1,
    ]);
    $pubInput = new PublicationInput($window->run->id, $window->run->recipe->hash, $window->run->generation);

    try {
        app(EstimatePublisher::class)->estimates($pubInput);
        $this->fail('Ожидалось исключение PublicationBlocked при пустых планах извлечения.');
    } catch (PublicationBlocked $blocked) {
        expect($blocked->reason)->toBe(BlockedReason::ExtractionIncomplete);
    }
});

test('ReceiptStore синхронизирует файл и цепочку директорий через fsync при put и inspect до принятия в БД и выбрасывает ReceiptUnavailable при отсутствии объекта', function (): void {
    $period = FinancialFixtures::reset();
    [, , , , $operation] = FinancialFixtures::authorized($period);
    FinancialFixtures::claim($operation);
    $call = app(RequestRepository::class)->get($operation->physicalCallId);
    $store = app(ReceiptStore::class);

    $verified = $store->put($call, 200, FinancialFixtures::body());
    expect($verified->status)->toBe(200)
        ->and($verified->hash)->toHaveLength(64);

    // syncDurablePath не обходит родительские директории при корне '/' или несовпадающем префиксе соседнего каталога.
    $syncMethod = new ReflectionMethod(ReceiptStore::class, 'syncDurablePath');
    $disk = Storage::disk($call->receiptDisk);
    $filePath = $disk->path($call->receiptUploadKey);
    $syncMethod->invoke($store, '/', $filePath);
    $syncMethod->invoke($store, rtrim($disk->path(''), DIRECTORY_SEPARATOR).'-sibling', $filePath);

    $inspected = $store->inspect($call);
    expect($inspected->hash)->toBe($verified->hash)
        ->and($inspected->status)->toBe(200);

    DB::transaction(fn () => app(RequestRepository::class)->acceptReceiptLocked($verified));
    $acceptedCall = app(RequestRepository::class)->get($operation->physicalCallId);
    Storage::disk($acceptedCall->receiptDisk)->delete($acceptedCall->receiptUploadKey);
    expect(fn () => $store->inspect($acceptedCall))->toThrow(ReceiptUnavailable::class);
});

test('StructuredCallExecutor освобождает claim ассета при отказе claimSend (not_sent) и переводит вызов в unknown при необработанном исключении транспорта после claimSend', function (): void {
    $server = new WireFixture;
    try {
        $f = auditCacheProviderFixtures($server);

        ExecutionFixtures::setMode('system', ControlMode::Paused);
        $denied = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
        expect($denied)->toBeInstanceOf(SendClaimResult::class)
            ->and($denied->denial)->toBe(ProviderSendDenial::Paused);

        ExecutionFixtures::setMode('system', ControlMode::Enabled);
        FinalizeFixtures::expire($f->work, 600);
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
            ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->outcome)->toBe('not_sent');

        $f->lease = FinalizeFixtures::running($f->work);
        $nextOp = app(AnalysisCallCoordinator::class)->authorize($f->lease, $f->run, $f->plan, $f->period, $f->wire);
        $succeeded = app(StructuredCallExecutor::class)->execute($nextOp, $f->wire, $f->gate());
        expect($succeeded)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($succeeded->status)->toBe(200)
            ->and(app(AssetRepository::class)->findCache($f->wire->assetKey)?->usable())->toBeTrue();

        app()->instance(StageRegistry::class, new StageRegistry);
        $gen = new ProviderSendFixtures($server);
        $throwingGate = new class($gen->operation->physicalCallId) implements ProviderSendGate
        {
            public function __construct(private string $callId) {}

            public function claimSend(AuthorizedProviderOperation $operation, ProviderWireRequest $wireRequest): SendClaimResult
            {
                $claimed = DB::transaction(fn () => app(RequestRepository::class)->claimFinancialLocked($operation, $wireRequest->fingerprint));
                expect($claimed)->toBeTrue();
                $transport = new PreparedProviderTransport(
                    Http::createPendingRequest(),
                    $wireRequest,
                    $this->callId,
                    hrtime(true) + 10_000_000_000,
                    Utils::streamFor(''),
                    static function (): bool {
                        throw new Exception('Simulated post-claim transport crash');
                    },
                );

                return new SendClaimResult($transport, null);
            }

            public function recordTransportOutcome(string $physicalCallId, ProviderTransportOutcome $outcome): void {}
        };

        expect(fn () => app(StructuredCallExecutor::class)->execute($gen->operation, $gen->wire, $throwingGate))
            ->toThrow(Exception::class, 'Simulated post-claim transport crash');

        $afterCrash = app(RequestRepository::class)->get($gen->operation->physicalCallId);
        expect($afterCrash->requestState)->toBe('unknown')
            ->and($afterCrash->outcome)->toBe('unknown')
            ->and(DB::table('llm_budget_reservations')->where('id', $gen->operation->reservationId)->value('state'))->toBe('reserved');
    } finally {
        $server->stop();
    }
});

test('конкурентные RequestLedger::replay и RequestRepository::acceptReceipt сериализуются на блокировке вызова в обоих порядках коммита', function (): void {
    $period = FinancialFixtures::reset();

    [$opA, $serializedVerifiedA, $callIdA, $cmdA] = auditPrepareRaceReceipt($period, 'race-capture-first');
    $resA = ConcurrentTransactions::concurrently([
        'winner' => 'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () { '
            .'$verified = unserialize(base64_decode('.$serializedVerifiedA.')); '
            .'app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->acceptReceiptLocked($verified); '
            .'return ["status" => "captured"]; });',
        'waiter' => 'try { return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () { '
            .'$call = app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->get('.$callIdA.'); '
            .'app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->replay($call, '.$cmdA.', "audit-op", "race"); '
            .'return ["status" => "replayed"]; }); } catch (\\App\\Exceptions\\Llm\\OperationDenied $e) { return ["status" => $e->getMessage()]; }',
    ]);
    expect($resA['winner']['status'])->toBe('captured')
        ->and($resA['waiter']['status'])->toBe('replay_requires_unresolved_missing_receipt')
        ->and(DB::table('llm_budget_reservations')->where('id', $opA->reservationId)->value('settled_amount'))->toBe('0.00200001');

    [$opB, $serializedVerifiedB, $callIdB, $cmdB] = auditPrepareRaceReceipt($period, 'race-replay-first');
    $resB = ConcurrentTransactions::concurrently([
        'winner' => 'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () { '
            .'$call = app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->get('.$callIdB.'); '
            .'$next = app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->replay($call, '.$cmdB.', "audit-op", "race"); '
            .'return ["logical_key" => $next->logicalKey]; });',
        'waiter' => 'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () { '
            .'$verified = unserialize(base64_decode('.$serializedVerifiedB.')); '
            .'return \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($verified) { '
            .'app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->acceptReceiptLocked($verified); '
            .'return ["outcome" => $verified->assessment->outcome->value]; }); });',
    ]);
    expect($resB['winner']['logical_key'])->toBeString()->not->toBeEmpty()
        ->and($resB['waiter']['outcome'])->toBe('success')
        ->and(DB::table('llm_budget_reservations')->where('id', $opB->reservationId)->value('settled_amount'))->toBe('0.00200001');
});

test('BillingReconciler и ai:cost-reconcile ловят взаимокомпенсирующиеся дневные расхождения, отвергают экспоненциальную запись, дублирующиеся алиасы колонок, лишние поля, невалидные даты и переполнение int', function (): void {
    $day1 = CarbonImmutable::parse('2026-03-01');
    $day2 = CarbonImmutable::parse('2026-03-02');
    $day3 = CarbonImmutable::parse('2026-03-03');
    $usageSource = auditUsageSource(
        [
            new UsageRow($day1, null, null, null, 10, 100, 0, 50, 100_000_000),
            new UsageRow($day2, null, null, null, 10, 100, 0, 50, 100_000_000),
        ],
        new UsageRow(null, null, null, null, 20, 200, 0, 100, 200_000_000),
        '2026-03-03',
    );

    $reconciler = new BillingReconciler($usageSource);
    app()->instance(BillingReconciler::class, $reconciler);

    $tmp = tempnam(sys_get_temp_dir(), 'billing_audit_');
    assert(is_string($tmp));

    try {
        file_put_contents($tmp, "date,cost,currency\n2026-03-01,190.00,USD\n2026-03-02,10.00,USD\n");
        $report = $reconciler->reconcile($day1, $day3, $tmp, 5.0);
        expect($report->deltaPercent)->toBe(0.0)
            ->and($report->totalExceedsThreshold)->toBeFalse()
            ->and($report->dailyExceedsThreshold)->toBeTrue()
            ->and($report->exceedsThreshold)->toBeTrue()
            ->and($report->maxDailyDeltaPercent)->toBe(90.0)
            ->and($report->sumAbsoluteDeltaMicros)->toBe(180_000_000)
            ->and($report->daysExceedingThreshold)->toBe(2);

        $this->artisan('ai:cost-reconcile', [
            '--from' => '2026-03-01',
            '--to' => '2026-03-03',
            '--file' => $tmp,
            '--fail-on-breach' => true,
        ])->assertExitCode(ConsoleCommand::FAILURE);

        file_put_contents($tmp, "date,cost,currency\n2026-03-01,0.0000005,USD\n");
        expect($reconciler->reconcile($day1, $day3, $tmp, 5.0)->exportedTotalMicros)->toBe(1);

        $overflowLines = array_merge(['date,cost,currency'], array_fill(0, 10, '2026-03-01,999999999999.99,USD'));
        foreach ([
            [$day1, $day3, "date,cost,currency\n2026-03-01,1e3,USD\n"],
            [$day1, $day3, implode("\n", $overflowLines)."\n"],
            [$day1, $day3, "date,day,cost\n2026-03-01,2026-03-01,1.00\n"],
            [$day1, $day3, "date,cost,amount\n2026-03-01,1.00,1.00\n"],
            [$day1, $day3, "date,cost,currency\n2026-03-01,1.00,USD,unexpected_extra\n"],
            [CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-05'), "date,cost,currency\n2026-02-30,1.00,USD\n"],
            [CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-05'), "date,cost,currency\n2026-3-1,1.00,USD\n"],
            [$day2, $day1, "date,cost,currency\n2026-03-01,1.00,USD\n"],
        ] as [$from, $to, $csv]) {
            file_put_contents($tmp, $csv);
            expect(fn () => $reconciler->reconcile($from, $to, $tmp))->toThrow(BillingExportUnreadable::class);
        }

        $this->artisan('ai:cost-reconcile', [
            '--from' => '2026-02-30',
            '--to' => '2026-03-03',
            '--file' => $tmp,
        ])->assertExitCode(ConsoleCommand::FAILURE);
    } finally {
        @unlink($tmp);
    }
});

test('AssetRepository::claim оценивает истечение expires_at по clock_timestamp СУБД и не позволяет перехватить незавершённый создание-вызов', function (): void {
    $server = new WireFixture;
    try {
        AssetFixtures::clearAssets();
        $f = auditCacheProviderFixtures($server);

        $outcome = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
        expect($outcome)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($outcome->status)->toBe(200);

        $assets = app(AssetRepository::class);
        $requests = app(RequestRepository::class);
        $cacheKey = $f->wire->assetKey;
        expect($cacheKey)->not->toBeNull();

        $makeCall = static function () use ($f, $cacheKey, $requests): array {
            $plan = new ProviderOperationPlan(
                new RequestIdentity($f->plan->identity->recipeHash, $cacheKey->hash, OperationPurpose::CacheCreate, 0, ProcessUuid::random()->value),
                $f->plan->context,
                $f->plan->pricing,
                $f->plan->consumerCorrelationId,
            );
            $wire = new ProviderWireRequest(
                $plan, $f->wire->method, $f->wire->endpoint, $f->wire->allowedOrigin, $f->wire->body,
                $f->wire->contentType, $f->wire->headers, $f->wire->assetKey, $f->wire->capabilities,
            );
            $op = DB::transaction(fn () => $requests->authorize($plan, ProcessUuid::random()->value, 1, $f->period, wire: $wire));

            return [$op, $requests->get($op->physicalCallId)];
        };

        [$nextOp, $nextCall] = $makeCall();

        DB::table('llm_context_caches')->update(['expires_at' => DB::raw("clock_timestamp() + interval '60 seconds'")]);
        expect(fn () => DB::transaction(fn () => $assets->claim($cacheKey, $nextCall)))
            ->toThrow(OperationDenied::class, 'asset_single_flight_or_unknown');

        DB::table('llm_context_caches')->update(['expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
        DB::transaction(fn () => $assets->claim($cacheKey, $nextCall));
        expect(DB::table('llm_context_caches')->value('creation_token'))->toBe($nextOp->physicalCallId);

        [, $thirdCall] = $makeCall();
        DB::table('llm_context_caches')->update(['expires_at' => DB::raw("clock_timestamp() - interval '10 seconds'")]);
        expect(fn () => DB::transaction(fn () => $assets->claim($cacheKey, $thirdCall)))
            ->toThrow(OperationDenied::class, 'asset_single_flight_or_unknown');
    } finally {
        $server->stop();
    }
});
