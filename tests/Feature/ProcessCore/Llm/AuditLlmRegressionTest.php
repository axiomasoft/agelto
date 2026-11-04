<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Procurement\Ai\RowRange;
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

test('ScreeningFinalizer переводит активный screening intent версии документа в terminal при публикации результата', function (): void {
    [$work, $lease, $window, $period] = ScreeningRuntimeFixtures::work();
    $intentId = LedgerFixtures::uuid();
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
    $usageSource = new class implements UsageSource
    {
        public function usage(UsageQuery $query): UsageReport
        {
            return new UsageReport(
                rows: [],
                signature: new UsageSignature(
                    sourceId: 'audit',
                    label: 'audit',
                    lagSeconds: 0,
                    horizonMonths: null,
                    horizonFrom: null,
                    asOf: CarbonImmutable::parse('2026-03-02'),
                ),
                beyondHorizon: false,
            );
        }
    };
    $reconciler = new BillingReconciler($usageSource);
    $tmp = tempnam(sys_get_temp_dir(), 'billing_audit_');
    assert(is_string($tmp));

    try {
        // 19.99 * 1_000_000 в float даёт 19989999.999999996, через bcmath — ровно 19_990_000.
        file_put_contents($tmp, "date,cost,currency\n2026-03-01,19.99,USD\n");
        $report = $reconciler->reconcile(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-02'),
            $tmp,
        );
        expect($report->exportedTotalMicros)->toBe(19_990_000);

        file_put_contents($tmp, "date,cost,currency\n2026-03-01,-0.01,USD\n");
        expect(fn () => $reconciler->reconcile(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-02'),
            $tmp,
        ))->toThrow(BillingExportUnreadable::class);

        file_put_contents($tmp, "date,cost,currency\n2026-03-01,not-a-number,USD\n");
        expect(fn () => $reconciler->reconcile(
            CarbonImmutable::parse('2026-03-01'),
            CarbonImmutable::parse('2026-03-02'),
            $tmp,
        ))->toThrow(BillingExportUnreadable::class);
    } finally {
        @unlink($tmp);
    }
});

test('ExtractionSemanticValidator округляет произведение quantity * unit_price по коммерческому правилу HALF_UP до 2 знаков, а EstimatePublisher блокирует публикацию без планов извлечения', function (): void {
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $validator = app(StructuredTaskValidator::class);

    // 1.5 * 0.01 = 0.015 -> HALF_UP = 0.02 (принимается), а усечение 0.01 отвергается с кодом totals_arithmetic.
    $payloadHalfUp = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
    $payloadHalfUp['rows'][0]['row']['quantity'] = '1.5';
    $payloadHalfUp['rows'][0]['row']['unit_price_curr'] = '0.01';
    $payloadHalfUp['rows'][0]['row']['total_price_curr'] = '0.02';
    $accepted = $validator->validate($task, json_encode($payloadHalfUp, JSON_THROW_ON_ERROR), 'STOP');
    expect($accepted->disposition)->toBe(StructuredDisposition::Accepted);

    $payloadTruncated = $payloadHalfUp;
    $payloadTruncated['rows'][0]['row']['total_price_curr'] = '0.01';
    $rejected = $validator->validate($task, json_encode($payloadTruncated, JSON_THROW_ON_ERROR), 'STOP');
    expect($rejected->disposition)->toBe(StructuredDisposition::InvalidSemantics)
        ->and($rejected->errors)->toContain('totals_arithmetic');

    // Высокая точность 0.333333 * 3.00 = 0.999999 -> HALF_UP = 1.00.
    $payloadHighPrecision = $payloadHalfUp;
    $payloadHighPrecision['rows'][0]['row']['quantity'] = '0.333333';
    $payloadHighPrecision['rows'][0]['row']['unit_price_curr'] = '3.00';
    $payloadHighPrecision['rows'][0]['row']['total_price_curr'] = '1.00';
    expect($validator->validate($task, json_encode($payloadHighPrecision, JSON_THROW_ON_ERROR), 'STOP')->disposition)
        ->toBe(StructuredDisposition::Accepted);

    // Отрицательная полусумма -1.5 * 0.01 = -0.015 -> HALF_UP по модулю = -0.02.
    $payloadNegative = $payloadHalfUp;
    $payloadNegative['rows'][0]['row']['quantity'] = '-1.5';
    $payloadNegative['rows'][0]['row']['unit_price_curr'] = '0.01';
    $payloadNegative['rows'][0]['row']['total_price_curr'] = '-0.02';
    expect($validator->validate($task, json_encode($payloadNegative, JSON_THROW_ON_ERROR), 'STOP')->disposition)
        ->toBe(StructuredDisposition::Accepted);

    // EstimatePublisher::estimates() блокирует публикацию при пустом списке планов извлечения.
    FinancialFixtures::reset();
    $window = AiFixtures::window(sealPlan: false);
    DB::table('prc_analysis_runs')
        ->where('id', $window->run->id->value)
        ->update([
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

    // До записи receipt_hash в БД inspect повторно проверяет и синхронизирует объект на диске.
    $inspected = $store->inspect($call);
    expect($inspected->hash)->toBe($verified->hash)
        ->and($inspected->status)->toBe(200);

    // После принятия в БД отсутствие закреплённого объекта на диске вызывает ReceiptUnavailable.
    DB::transaction(fn () => app(RequestRepository::class)->acceptReceiptLocked($verified));
    $acceptedCall = app(RequestRepository::class)->get($operation->physicalCallId);
    Storage::disk($acceptedCall->receiptDisk)->delete($acceptedCall->receiptUploadKey);
    expect(fn () => $store->inspect($acceptedCall))->toThrow(ReceiptUnavailable::class);
});

test('StructuredCallExecutor освобождает claim ассета при отказе claimSend (not_sent) и переводит вызов в unknown при необработанном исключении транспорта после claimSend', function (): void {
    $server = new WireFixture;
    try {
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

        // 1. Seam 1: пауза контрола блокирует claimSend, но после истечения и восстановления следующий вызов сразу перехватывает creation_token без ожидания 120с.
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

        // 2. Seam 2: необработанное исключение (\Exception / \Error) внутри sendOnce после успешного claimSend переводит запрос в unknown и сохраняет резерв бюджета.
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
    $requests = app(RequestRepository::class);

    // Сценарий А: replay проверил inspect()==null до появления файла, но acceptReceipt захватывает блокировку строки в БД первым и фиксирует receipt -> ждущий RequestRepository::replay получает отказ replay_requires_unresolved_missing_receipt.
    $opA = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('race-capture-first'), LedgerFixtures::uuid()->value, 1, $period));
    FinancialFixtures::claim($opA);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($opA->physicalCallId)));
    $callA = $requests->get($opA->physicalCallId);
    expect(app(ReceiptStore::class)->inspect($callA))->toBeNull();
    $verifiedA = app(ReceiptStore::class)->put($callA, 200, FinancialFixtures::body());
    $serializedVerifiedA = var_export(base64_encode(serialize($verifiedA)), true);
    $callIdA = var_export($opA->physicalCallId, true);
    $cmdA = var_export(LedgerFixtures::uuid()->value, true);

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

    // Сценарий Б: RequestRepository::replay захватывает блокировку первым (после inspect()==null вне транзакции) -> ждущий acceptReceiptLocked после него штатно рассчитывает исходный вызов.
    $opB = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('race-replay-first'), LedgerFixtures::uuid()->value, 1, $period));
    FinancialFixtures::claim($opB);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($opB->physicalCallId)));
    $callB = $requests->get($opB->physicalCallId);
    expect(app(ReceiptStore::class)->inspect($callB))->toBeNull();
    $verifiedB = app(ReceiptStore::class)->put($callB, 200, FinancialFixtures::body());
    $serializedVerifiedB = var_export(base64_encode(serialize($verifiedB)), true);
    $callIdB = var_export($opB->physicalCallId, true);
    $cmdB = var_export(LedgerFixtures::uuid()->value, true);

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
    $usageSource = new class($day1, $day2) implements UsageSource
    {
        public function __construct(private CarbonImmutable $d1, private CarbonImmutable $d2) {}

        public function usage(UsageQuery $query): UsageReport
        {
            $rows = $query->byDay
                ? [
                    new UsageRow($this->d1, null, null, null, 10, 100, 0, 50, 100_000_000),
                    new UsageRow($this->d2, null, null, null, 10, 100, 0, 50, 100_000_000),
                ]
                : [
                    new UsageRow(null, null, null, null, 20, 200, 0, 100, 200_000_000),
                ];

            return new UsageReport(
                rows: $rows,
                signature: new UsageSignature(
                    sourceId: 'audit',
                    label: 'audit',
                    lagSeconds: 0,
                    horizonMonths: null,
                    horizonFrom: null,
                    asOf: CarbonImmutable::parse('2026-03-03'),
                ),
                beyondHorizon: false,
            );
        }
    };

    $reconciler = new BillingReconciler($usageSource);
    app()->instance(BillingReconciler::class, $reconciler);

    $tmp = tempnam(sys_get_temp_dir(), 'billing_audit_');
    assert(is_string($tmp));

    try {
        // 1. AG-32: день 1 (+90%: 190 против 100 USD), день 2 (-90%: 10 против 100 USD), суммарная дельта 0%, но дневной порог 5% превышен.
        file_put_contents($tmp, "date,cost,currency\n2026-03-01,190.00,USD\n2026-03-02,10.00,USD\n");
        $report = $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp, 5.0);
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

        // 2. Суб-микродолларовое значение 0.0000005 округляется HALF_UP до 1 микродоллара.
        file_put_contents($tmp, "date,cost,currency\n2026-03-01,0.0000005,USD\n");
        $subMicro = $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp, 5.0);
        expect($subMicro->exportedTotalMicros)->toBe(1);

        // 3. AG-33: экспоненциальная нотация (1e3) и переполнение при сложении отвергаются.
        file_put_contents($tmp, "date,cost,currency\n2026-03-01,1e3,USD\n");
        expect(fn () => $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        $overflowLines = ['date,cost,currency'];
        for ($i = 0; $i < 10; $i++) {
            $overflowLines[] = '2026-03-01,999999999999.99,USD';
        }
        file_put_contents($tmp, implode("\n", $overflowLines)."\n");
        expect(fn () => $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        // 4. AG-34: конфликтующие алиасы заголовков (date+day, cost+amount) и лишние колонки в строке данных отвергаются.
        file_put_contents($tmp, "date,day,cost\n2026-03-01,2026-03-01,1.00\n");
        expect(fn () => $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        file_put_contents($tmp, "date,cost,amount\n2026-03-01,1.00,1.00\n");
        expect(fn () => $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        file_put_contents($tmp, "date,cost,currency\n2026-03-01,1.00,USD,unexpected_extra\n");
        expect(fn () => $reconciler->reconcile($day1, CarbonImmutable::parse('2026-03-03'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        // 5. AG-35: несуществующая календарная дата (2026-02-30), неполный формат (2026-3-1) и период from >= to отвергаются.
        file_put_contents($tmp, "date,cost,currency\n2026-02-30,1.00,USD\n");
        expect(fn () => $reconciler->reconcile(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-05'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        file_put_contents($tmp, "date,cost,currency\n2026-3-1,1.00,USD\n");
        expect(fn () => $reconciler->reconcile(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-03-05'), $tmp))
            ->toThrow(BillingExportUnreadable::class);

        file_put_contents($tmp, "date,cost,currency\n2026-03-01,1.00,USD\n");
        expect(fn () => $reconciler->reconcile($day2, $day1, $tmp))
            ->toThrow(BillingExportUnreadable::class);

        $this->artisan('ai:cost-reconcile', [
            '--from' => '2026-02-30',
            '--to' => '2026-03-03',
            '--file' => $tmp,
        ])->assertExitCode(ConsoleCommand::FAILURE);
    } finally {
        @unlink($tmp);
    }
});
