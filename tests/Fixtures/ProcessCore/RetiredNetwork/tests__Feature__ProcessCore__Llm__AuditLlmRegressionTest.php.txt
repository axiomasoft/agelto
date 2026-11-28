<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\ProviderCall;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Llm\ProviderTransportFailure;
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
use App\Repositories\Llm\Request\FinancialReservationRepository;
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
use App\Services\Llm\Providers\GeminiGenerateContentClient;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Extraction\AnalysisProviderSendGate;
use App\Services\Procurement\Extraction\EstimatePublisher;
use App\Services\Procurement\Extraction\ExtractionContract;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
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
use Symfony\Component\Yaml\Yaml;
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

test('FinancialReservationRepository требует активную транзакцию и проверяет идентичность резерва и состояние при расчёте и сверке по квитанции', function (): void {
    $period = FinancialFixtures::reset();
    expect(DB::connection()->transactionLevel())->toBe(0);

    $finance = app(FinancialReservationRepository::class);
    $requests = app(RequestRepository::class);
    [, , , , $operation] = FinancialFixtures::authorized($period);
    $call = $requests->get($operation->physicalCallId);
    $accountRow = DB::transaction(fn () => $finance->lockAccount($call->context->account, $call->currency));
    expect(DB::connection()->transactionLevel())->toBe(0);

    expect(fn () => $finance->lockAccount($call->context->account, $call->currency))
        ->toThrow(LogicException::class, 'Financial operation requires caller transaction.')
        ->and(fn () => $finance->lockPeriod($period, $call->context->account, $call->currency))
        ->toThrow(LogicException::class, 'Financial operation requires caller transaction.')
        ->and(fn () => $finance->assertSendEligible($accountRow, '0.00100000'))
        ->toThrow(LogicException::class, 'Financial operation requires caller transaction.')
        ->and(fn () => $finance->settleLocked($call, '0.00200001'))
        ->toThrow(LogicException::class, 'Financial operation requires caller transaction.');

    $withMismatch = static fn (ProviderCall $base, ?string $physicalCallId = null, ?string $periodId = null, ?string $upperBound = null): ProviderCall => new ProviderCall(
        new AuthorizedProviderOperation(
            $base->authorization->logicalRequestId,
            $physicalCallId ?? $base->authorization->physicalCallId,
            $base->authorization->requestEpoch,
            $base->authorization->sendAuthorizationToken,
            $base->authorization->ownerAttemptId,
            $base->authorization->ownerWorkGeneration,
            $base->authorization->reservationId,
        ),
        $base->purpose,
        $base->recipeHash,
        $base->inputHash,
        $base->context,
        $base->currency,
        $periodId ?? $base->periodId,
        $upperBound ?? $base->upperBound,
        $base->requestState,
        $base->claimed,
        $base->receiptId,
        $base->receiptDisk,
        $base->receiptUploadKey,
        $base->receiptHash,
        $base->outcome,
        $base->recordedAssessment,
        $base->wireFingerprint,
    );

    foreach ([
        $withMismatch($call, physicalCallId: ProcessUuid::random()->value),
        $withMismatch($call, periodId: ProcessUuid::random()->value),
        $withMismatch($call, upperBound: '0.00999999'),
    ] as $mismatchedBefore) {
        expect(fn () => DB::transaction(fn () => $finance->settleLocked($mismatchedBefore, '0.00200001')))
            ->toThrow(OperationDenied::class, 'reservation_identity_mismatch');
    }

    expect(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe($call->upperBound)
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('state'))->toBe('reserved');

    DB::transaction(function () use ($finance, $call): void {
        $finance->lockAccount($call->context->account, $call->currency);
        $finance->lockPeriod($call->periodId, $call->context->account, $call->currency);
        $finance->settleLocked($call, '0.00200001');
    });

    expect(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('state'))->toBe('settled')
        ->and(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('settled_amount'))->toBe('0.00200001')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe('0.00200001');

    DB::transaction(function () use ($finance, $call): void {
        $finance->lockAccount($call->context->account, $call->currency);
        $finance->lockPeriod($call->periodId, $call->context->account, $call->currency);
        $finance->settleLocked($call, '0.00200001');
    });

    expect(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe('0.00200001');

    foreach ([
        $withMismatch($call, physicalCallId: ProcessUuid::random()->value),
        $withMismatch($call, periodId: ProcessUuid::random()->value),
        $withMismatch($call, upperBound: '0.00999999'),
    ] as $mismatchedAfter) {
        expect(fn () => DB::transaction(fn () => $finance->settleLocked($mismatchedAfter, '0.00200001')))
            ->toThrow(OperationDenied::class, 'reservation_identity_mismatch');
    }

    expect(fn () => DB::transaction(fn () => $finance->settleLocked($call, '0.00100000', hash('sha256', 'unbacked-receipt'))))
        ->toThrow(OperationDenied::class, 'settlement_conflict');

    expect(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe('0.00200001')
        ->and(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('settled_amount'))->toBe('0.00200001')
        ->and(DB::table('llm_financial_actions')->where('physical_call_id', $operation->physicalCallId)->count())->toBe(0);

    [, , , , $op2] = FinancialFixtures::authorized($period);
    FinancialFixtures::claim($op2);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($op2->physicalCallId)));
    $call2 = $requests->get($op2->physicalCallId);
    $requests->abandon($call2, 'audit-operator', 'conservative close', conservativelySettle: true);

    expect(DB::table('llm_budget_reservations')->where('id', $op2->reservationId)->value('settled_amount'))->toBe($call2->upperBound)
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe(bcadd('0.00200001', $call2->upperBound, 8));

    $receiptHash = hash('sha256', 'verified-late-receipt');
    DB::transaction(function () use ($finance, $call2, $receiptHash): void {
        $finance->lockAccount($call2->context->account, $call2->currency);
        $finance->lockPeriod($call2->periodId, $call2->context->account, $call2->currency);
        $finance->settleLocked($call2, '0.00150000', $receiptHash);
    });

    expect(DB::table('llm_budget_reservations')->where('id', $op2->reservationId)->value('settled_amount'))->toBe('0.00150000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe('0.00350001')
        ->and(DB::table('llm_financial_actions')->where('physical_call_id', $op2->physicalCallId)->where('action', 'receipt_reconciliation')->count())->toBe(1);

    DB::transaction(function () use ($finance, $call2, $receiptHash): void {
        $finance->lockAccount($call2->context->account, $call2->currency);
        $finance->lockPeriod($call2->periodId, $call2->context->account, $call2->currency);
        $finance->settleLocked($call2, '0.00150000', $receiptHash);
    });

    expect(DB::table('llm_budget_reservations')->where('id', $op2->reservationId)->value('settled_amount'))->toBe('0.00150000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $period)->value('spent'))->toBe('0.00350001')
        ->and(DB::table('llm_financial_actions')->where('physical_call_id', $op2->physicalCallId)->where('action', 'receipt_reconciliation')->count())->toBe(1);
});

test('ReceiptStore проверяет атомарный контракт локального хранилища квитанций вне транзакции и общую топологию тома app_storage', function (): void {
    app()->instance(StageRegistry::class, new StageRegistry);
    FinancialFixtures::reset();
    $store = app(ReceiptStore::class);

    $store->verifyStorageContract('local');
    $probeFiles = glob(Storage::disk('local')->path('llm/receipts/.probe-*')) ?: [];
    expect($probeFiles)->toBe([]);

    expect(fn () => DB::transaction(fn () => $store->verifyStorageContract('local')))
        ->toThrow(LogicException::class, 'Receipt object I/O is forbidden inside DB transactions.');

    $rawCompose = file_get_contents(base_path('docker-compose.prod.yml'));
    assert(is_string($rawCompose));
    $sanitizedCompose = preg_replace('/\$\{[^}]+\}/', 'placeholder', $rawCompose);
    assert(is_string($sanitizedCompose));
    $compose = Yaml::parse($sanitizedCompose);
    assert(is_array($compose));

    ReceiptStore::verifySharedTopology($compose);

    $withoutReconciler = $compose;
    $withoutReconciler['services']['process-reconciler']['volumes'] = [];
    expect(fn () => ReceiptStore::verifySharedTopology($withoutReconciler))
        ->toThrow(ReceiptUnavailable::class);

    $withoutHorizon = $compose;
    $withoutHorizon['services']['horizon']['volumes'] = [];
    expect(fn () => ReceiptStore::verifySharedTopology($withoutHorizon))
        ->toThrow(ReceiptUnavailable::class);
});

test('HttpTransport и AnalysisProviderSendGate сохраняют раздельные счётчики wire и entity байт и метрики cURL без подмены через strlen тела ответа', function (): void {
    $server = new WireFixture;
    $withPath = static fn (string $path): Closure => static function (ProviderOperationPlan $base, string $origin) use ($path): array {
        $compiled = (new GeminiGenerateContentClient($origin))->compile($base);
        $wire = new ProviderWireRequest(
            $base,
            $compiled->method,
            $origin.$path,
            $compiled->allowedOrigin,
            $compiled->body,
            $compiled->contentType,
            $compiled->headers,
            $compiled->assetKey,
            $compiled->capabilities,
        );

        return [$base, $wire];
    };

    $newFixture = static function (?Closure $operationFactory = null) use ($server): ProviderSendFixtures {
        app()->instance(StageRegistry::class, new StageRegistry);

        return new ProviderSendFixtures($server, operationFactory: $operationFactory);
    };

    try {
        // 1. Успешный транспорт фиксирует реальные wireBytes, entityBytes, wireMeasured и cURL-метрики в prc_request_attempts и prc_observations.
        $fSuccess = $newFixture();
        $expectedBytes = strlen(FinancialFixtures::body());
        $outcome = app(StructuredCallExecutor::class)->execute($fSuccess->operation, $fSuccess->wire, $fSuccess->gate());
        expect($outcome)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($outcome->physicalCallId)->toBe($fSuccess->operation->physicalCallId)
            ->and($outcome->status)->toBe(200)
            ->and($outcome->failure)->toBeNull()
            ->and($outcome->wireBytes)->toBe($expectedBytes)
            ->and($outcome->entityBytes)->toBe($expectedBytes)
            ->and($outcome->wireMeasured)->toBeTrue()
            ->and($outcome->curlCode)->toBe(0)
            ->and($outcome->stats)->toBeArray()
            ->and($outcome->stats['http_code'] ?? null)->toBe(200)
            ->and($outcome->stats['size_download'] ?? null)->toBe($expectedBytes)
            ->and($outcome->__debugInfo())->toMatchArray([
                'physical_call_id' => $fSuccess->operation->physicalCallId,
                'status' => 200,
                'failure' => null,
                'wire_bytes' => $expectedBytes,
                'entity_bytes' => $expectedBytes,
                'wire_measured' => true,
            ]);

        $attempt = DB::table('prc_request_attempts')->where('request_id', $fSuccess->operation->physicalCallId)->sole();
        $obsDetails = json_decode((string) DB::table('prc_observations')->where('request_id', $fSuccess->operation->physicalCallId)->value('details'), true, flags: JSON_THROW_ON_ERROR);
        expect($attempt->outcome)->toBe('response')
            ->and((int) $attempt->status)->toBe(200)
            ->and((int) $attempt->bytes)->toBe($expectedBytes)
            ->and($obsDetails['transport']['size_download'] ?? null)->toBe($expectedBytes)
            ->and($obsDetails['transport']['http_code'] ?? null)->toBe(200)
            ->and($obsDetails['transport']['errno'] ?? null)->toBe(0);

        // 2. AnalysisProviderSendGate::recordTransportOutcome никогда не подменяет счётчики через strlen(body).
        $fDistinct = $newFixture();
        $gateDistinct = $fDistinct->gate();
        $claimDistinct = $gateDistinct->claimSend($fDistinct->operation, $fDistinct->wire);
        expect($claimDistinct->transport)->not->toBeNull();
        $syntheticOutcome = new ProviderTransportOutcome(
            physicalCallId: $fDistinct->operation->physicalCallId,
            status: 200,
            body: str_repeat('X', 120),
            failure: null,
            headers: ['content-type' => ['application/json']],
            wireBytes: 7,
            entityBytes: 5,
            wireMeasured: true,
            curlCode: 0,
            stats: ['http_code' => 200, 'size_download' => 7],
        );
        $gateDistinct->recordTransportOutcome($fDistinct->operation->physicalCallId, $syntheticOutcome);
        $distinctAttempt = DB::table('prc_request_attempts')->where('request_id', $fDistinct->operation->physicalCallId)->sole();
        $distinctObs = json_decode((string) DB::table('prc_observations')->where('request_id', $fDistinct->operation->physicalCallId)->value('details'), true, flags: JSON_THROW_ON_ERROR);
        expect((int) $distinctAttempt->bytes)->toBe(5)
            ->and($distinctObs['transport']['size_download'] ?? null)->toBe(7);

        // 3a. Обрыв частично переданного тела (/truncated) сохраняет принятые байты без превращения ответа в квитанцию или not_sent.
        $fTruncated = $newFixture($withPath('/truncated'));
        $truncatedOutcome = app(StructuredCallExecutor::class)->execute($fTruncated->operation, $fTruncated->wire, $fTruncated->gate());
        $partialLen = strlen('Привет');
        expect($truncatedOutcome)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($truncatedOutcome->status)->toBeNull()
            ->and($truncatedOutcome->body())->toBeNull()
            ->and($truncatedOutcome->failure)->toBe(ProviderTransportFailure::Truncated)
            ->and($truncatedOutcome->wireBytes)->toBe($partialLen)
            ->and($truncatedOutcome->entityBytes)->toBe($partialLen)
            ->and($truncatedOutcome->wireMeasured)->toBeTrue()
            ->and($truncatedOutcome->curlCode)->toBe(18);
        $truncAttempt = DB::table('prc_request_attempts')->where('request_id', $fTruncated->operation->physicalCallId)->sole();
        $truncObs = json_decode((string) DB::table('prc_observations')->where('request_id', $fTruncated->operation->physicalCallId)->value('details'), true, flags: JSON_THROW_ON_ERROR);
        $truncCall = app(RequestRepository::class)->get($fTruncated->operation->physicalCallId);
        expect($truncAttempt->outcome)->toBe('truncated')
            ->and((int) $truncAttempt->bytes)->toBe($partialLen)
            ->and($truncObs['transport']['size_download'] ?? null)->toBe($partialLen)
            ->and($truncObs['transport']['errno'] ?? null)->toBe(18)
            ->and($truncCall->requestState)->toBe('unknown')
            ->and($truncCall->outcome)->toBe('unknown')
            ->and($truncCall->receiptHash)->toBeNull()
            ->and(DB::table('llm_budget_reservations')->where('id', $fTruncated->operation->reservationId)->value('state'))->toBe('reserved');

        // 3b. Отклонение чанка по лимиту BoundedSink (/large) сохраняет только принятые sink entityBytes без отвергнутого чанка.
        $fLimit = $newFixture($withPath('/large'));
        $limitOutcome = app(StructuredCallExecutor::class)->execute($fLimit->operation, $fLimit->wire, $fLimit->gate());
        expect($limitOutcome)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($limitOutcome->status)->toBeNull()
            ->and($limitOutcome->body())->toBeNull()
            ->and($limitOutcome->failure)->toBe(ProviderTransportFailure::Truncated)
            ->and($limitOutcome->wireMeasured)->toBeTrue()
            ->and($limitOutcome->entityBytes)->toBeGreaterThan(0)
            ->and($limitOutcome->entityBytes)->toBeLessThanOrEqual(65_536)
            ->and($limitOutcome->wireBytes)->toBeGreaterThanOrEqual($limitOutcome->entityBytes);
        $limitAttempt = DB::table('prc_request_attempts')->where('request_id', $fLimit->operation->physicalCallId)->sole();
        expect($limitAttempt->outcome)->toBe('truncated')
            ->and((int) $limitAttempt->bytes)->toBe($limitOutcome->entityBytes)
            ->and(app(RequestRepository::class)->get($fLimit->operation->physicalCallId)->requestState)->toBe('unknown');

        // 3c. Отмена (потеря lease в progress после получения части тела) сохраняет накопленные wireBytes и entityBytes.
        $fCancel = $newFixture($withPath('/body-partial-stall'));
        $cancelContext = new ExecutionContext(
            lease: $fCancel->lease,
            keeper: app(LeaseKeeper::class),
            clock: app(ProcessClock::class),
            slice: Duration::seconds(60),
            leaseDuration: Duration::seconds(60),
            heartbeatInterval: Duration::seconds(1),
            finalizationReserve: Duration::seconds(5),
            leaseSafety: Duration::seconds(5),
        );
        $cancelGate = ProviderSendFixtures::gateFor($fCancel->lease, $fCancel->run, $fCancel->target, $fCancel->route, $fCancel->profile, $server, $cancelContext);
        $cancelClaim = $cancelGate->claimSend($fCancel->operation, $fCancel->wire);
        expect($cancelClaim->transport)->not->toBeNull();
        $cancelContext->tick();
        FinalizeFixtures::revoke($fCancel->work, true);
        $cancelOutcome = $cancelClaim->transport->sendOnce($fCancel->wire);
        $cancelGate->recordTransportOutcome($fCancel->operation->physicalCallId, $cancelOutcome);
        expect($cancelOutcome->status)->toBeNull()
            ->and($cancelOutcome->body())->toBeNull()
            ->and($cancelOutcome->failure)->toBe(ProviderTransportFailure::Interrupted)
            ->and($cancelOutcome->wireBytes)->toBe(12_415)
            ->and($cancelOutcome->entityBytes)->toBe(12_415)
            ->and($cancelOutcome->wireMeasured)->toBeTrue();
        $cancelAttempt = DB::table('prc_request_attempts')->where('request_id', $fCancel->operation->physicalCallId)->sole();
        expect($cancelAttempt->outcome)->toBe('cancelled')
            ->and((int) $cancelAttempt->bytes)->toBe(12_415);

        // 4a. Истечение deadline до старта HTTP-вызова возвращает 0 байт, wireMeasured === false и stats === null.
        $fDeadline = $newFixture();
        $baseClock = app(ProcessClock::class);
        $offsetNs = 0;
        $deadlineClock = new class($baseClock, $offsetNs) implements ProcessClock
        {
            public function __construct(private ProcessClock $inner, public int &$offsetNs) {}

            public function dbNow(): DbInstant
            {
                return $this->inner->dbNow();
            }

            public function monotonicNs(): int
            {
                return $this->inner->monotonicNs() + $this->offsetNs;
            }

            public function sleep(Duration $duration): void
            {
                $this->inner->sleep($duration);
            }
        };
        $deadlineContext = new ExecutionContext(
            lease: $fDeadline->lease,
            keeper: app(LeaseKeeper::class),
            clock: $deadlineClock,
            slice: Duration::seconds(60),
            leaseDuration: Duration::seconds(180),
            heartbeatInterval: Duration::seconds(60),
            finalizationReserve: Duration::seconds(5),
            leaseSafety: Duration::seconds(5),
        );
        $deadlineGate = ProviderSendFixtures::gateFor($fDeadline->lease, $fDeadline->run, $fDeadline->target, $fDeadline->route, $fDeadline->profile, $server, $deadlineContext);
        $deadlineClaim = $deadlineGate->claimSend($fDeadline->operation, $fDeadline->wire);
        expect($deadlineClaim->transport)->not->toBeNull();
        $offsetNs = 52_000_000_000;
        $deadlineOutcome = $deadlineClaim->transport->sendOnce($fDeadline->wire);
        $deadlineGate->recordTransportOutcome($fDeadline->operation->physicalCallId, $deadlineOutcome);
        expect($deadlineOutcome->status)->toBeNull()
            ->and($deadlineOutcome->body())->toBeNull()
            ->and($deadlineOutcome->failure)->toBe(ProviderTransportFailure::Deadline)
            ->and($deadlineOutcome->wireBytes)->toBe(0)
            ->and($deadlineOutcome->entityBytes)->toBe(0)
            ->and($deadlineOutcome->wireMeasured)->toBeFalse()
            ->and($deadlineOutcome->stats)->toBeNull();
        $deadlineAttempt = DB::table('prc_request_attempts')->where('request_id', $fDeadline->operation->physicalCallId)->sole();
        $deadlineObs = json_decode((string) DB::table('prc_observations')->where('request_id', $fDeadline->operation->physicalCallId)->value('details'), true, flags: JSON_THROW_ON_ERROR);
        expect($deadlineAttempt->outcome)->toBe('cancelled')
            ->and((int) $deadlineAttempt->bytes)->toBe(0)
            ->and($deadlineObs['transport'])->toBeNull();

        // 4b. Таймаут до получения тела (/stall) записывает 0 байт и сохраняет вызов в unknown с активным резервом.
        $fTimeout = $newFixture($withPath('/stall'));
        $timeoutOutcome = app(StructuredCallExecutor::class)->execute($fTimeout->operation, $fTimeout->wire, $fTimeout->gate());
        expect($timeoutOutcome)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($timeoutOutcome->status)->toBeNull()
            ->and($timeoutOutcome->body())->toBeNull()
            ->and($timeoutOutcome->failure)->toBe(ProviderTransportFailure::Timeout)
            ->and($timeoutOutcome->wireBytes)->toBe(0)
            ->and($timeoutOutcome->entityBytes)->toBe(0)
            ->and($timeoutOutcome->wireMeasured)->toBeTrue()
            ->and($timeoutOutcome->curlCode)->toBe(28);
        $timeoutAttempt = DB::table('prc_request_attempts')->where('request_id', $fTimeout->operation->physicalCallId)->sole();
        $timeoutObs = json_decode((string) DB::table('prc_observations')->where('request_id', $fTimeout->operation->physicalCallId)->value('details'), true, flags: JSON_THROW_ON_ERROR);
        $timeoutCall = app(RequestRepository::class)->get($fTimeout->operation->physicalCallId);
        expect($timeoutAttempt->outcome)->toBe('timeout')
            ->and((int) $timeoutAttempt->bytes)->toBe(0)
            ->and($timeoutObs['transport']['size_download'] ?? null)->toBe(0)
            ->and($timeoutObs['transport']['errno'] ?? null)->toBe(28)
            ->and($timeoutCall->requestState)->toBe('unknown')
            ->and($timeoutCall->outcome)->toBe('unknown')
            ->and(DB::table('llm_budget_reservations')->where('id', $fTimeout->operation->reservationId)->value('state'))->toBe('reserved');

        // 4c. Ошибка подключения к прокси фиксирует ProxyConnect с 0 байт и удерживает резерв в состоянии unknown.
        $fProxy = $newFixture();
        $proxySelector = new class($fProxy->route) implements OutboundRouteSelector
        {
            public function __construct(private ProcessUuid $route) {}

            public function select(OutboundRequestPlan $plan, RetryRoutePreference $preference): SelectedNetworkRoute
            {
                return new SelectedNetworkRoute(
                    new NetworkRouteSelection($this->route),
                    new HttpRouteBinding($this->route, 'http://127.0.0.1:1'),
                );
            }
        };
        $proxyGate = app()->make(AnalysisProviderSendGate::class, [
            'context' => app(ExecutionContextFactory::class)->open($fProxy->lease),
            'runId' => $fProxy->run,
            'target' => $fProxy->target,
            'profile' => $fProxy->profile,
            'urlPolicy' => $server->policy(),
            'routes' => $proxySelector,
            'apiKey' => 'fixture-only-key',
        ]);
        $proxyOutcome = app(StructuredCallExecutor::class)->execute($fProxy->operation, $fProxy->wire, $proxyGate);
        expect($proxyOutcome)->toBeInstanceOf(ProviderTransportOutcome::class)
            ->and($proxyOutcome->status)->toBeNull()
            ->and($proxyOutcome->body())->toBeNull()
            ->and($proxyOutcome->failure)->toBe(ProviderTransportFailure::ProxyConnect)
            ->and($proxyOutcome->wireBytes)->toBe(0)
            ->and($proxyOutcome->entityBytes)->toBe(0)
            ->and($proxyOutcome->wireMeasured)->toBeTrue()
            ->and($proxyOutcome->curlCode)->toBe(7);
        $proxyAttempt = DB::table('prc_request_attempts')->where('request_id', $fProxy->operation->physicalCallId)->sole();
        $proxyObs = json_decode((string) DB::table('prc_observations')->where('request_id', $fProxy->operation->physicalCallId)->value('details'), true, flags: JSON_THROW_ON_ERROR);
        $proxyCall = app(RequestRepository::class)->get($fProxy->operation->physicalCallId);
        expect($proxyAttempt->outcome)->toBe('proxy_connect')
            ->and((int) $proxyAttempt->bytes)->toBe(0)
            ->and($proxyObs['transport']['size_download'] ?? null)->toBe(0)
            ->and($proxyObs['transport']['errno'] ?? null)->toBe(7)
            ->and($proxyCall->requestState)->toBe('unknown')
            ->and($proxyCall->outcome)->toBe('unknown')
            ->and(DB::table('llm_budget_reservations')->where('id', $fProxy->operation->reservationId)->value('state'))->toBe('reserved');
    } finally {
        $server->stop();
    }
});
