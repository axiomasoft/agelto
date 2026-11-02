<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Enums\Llm\OperationPurpose;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\BillingReconciler;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\RequestLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('p39', 't30', 't22');

beforeEach(function (): void {
    $this->period = FinancialFixtures::reset();
});

test('два реальных PG contenders max-cost reserve не превышают cap и отказ откатывает intent', function (): void {
    DB::table('llm_budget_periods')->where('id', $this->period)->update(['cap' => '0.01000000']);
    $operation = function (string $seed): string {
        return '$plan = \\Tests\\Support\\ProcessCore\\FinancialFixtures::genericPlan('.var_export($seed, true).');'
            .'$authorization = app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->authorize($plan, (string) \\Illuminate\\Support\\Str::uuid(), 1, '.var_export($this->period, true).');'
            .'return ["physical" => $authorization->physicalCallId, "pid" => \\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS id")->id];';
    };
    $results = ConcurrentTransactions::concurrently([
        'winner' => 'return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::holdUntilWaiter(function () { '.$operation('first').' });',
        'waiter' => 'try { return \\Tests\\Support\\ProcessCore\\FinalizeFixtures::afterHolder(function () { return \\Illuminate\\Support\\Facades\\DB::transaction(function () { '.$operation('second').' }); }); } catch (\\App\\Exceptions\\Llm\\OperationDenied $e) { return ["reason" => $e->getMessage(), "pid" => \\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS id")->id]; }',
    ]);
    expect($results['winner']['pid'])->not->toBe($results['waiter']['pid'])
        ->and($results['waiter']['reason'])->toBe('provider_budget')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('llm_provider_requests')->count())->toBe(1)
        ->and(DB::table('llm_budget_periods')->value('reserved'))->toBe(FinancialFixtures::pricing()->upperBound);
});

test('multiple in-flight unknown превышают trigger count но сохраняют все резервы и останавливают готовую authorization', function (): void {
    $requests = app(RequestRepository::class);
    $operations = [];
    for ($n = 0; $n < 3; $n++) {
        $operations[] = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('parallel-'.$n), LedgerFixtures::uuid()->value, 1, $this->period));
    }
    FinancialFixtures::claim($operations[0]);
    FinancialFixtures::claim($operations[1]);
    foreach ([$operations[0], $operations[1]] as $operation) {
        DB::transaction(fn () => $requests->interruptLocked($requests->get($operation->physicalCallId)));
    }
    expect(DB::table('llm_provider_requests')->where('state', 'unknown')->count())->toBe(2)
        ->and(DB::table('llm_budget_reservations')->where('state', 'reserved')->count())->toBe(3)
        ->and(fn () => FinancialFixtures::claim($operations[2]))->toThrow(OperationDenied::class, 'account_unknown_hold');
    // An aggregate CSV/daily total has no operation identity and no settlement API here.
    Storage::disk('local')->put('billing.csv', "date,cost,currency\n2026-11-02,0,USD\n");
    $aggregate = app(BillingReconciler::class)->reconcile(CarbonImmutable::parse('2026-11-02'),
        CarbonImmutable::parse('2026-11-03'), Storage::disk('local')->path('billing.csv'));
    expect($aggregate->exportedTotalMicros)->toBe(0)->and(DB::table('llm_budget_reservations')->where('state', 'reserved')->count())->toBe(3);
    app(RequestLedger::class)->capture($requests->get($operations[0]->physicalCallId), 200, FinancialFixtures::body());
    expect(fn () => FinancialFixtures::claim($operations[2]))->toThrow(OperationDenied::class, 'account_unknown_hold');
    app(RequestLedger::class)->capture($requests->get($operations[1]->physicalCallId), 200, FinancialFixtures::body());
    expect(FinancialFixtures::claim($operations[2]))->toBeTrue();
    Http::assertNothingSent();
});

test('configured threshold использует >= и hard exposure включает unclaimed и claimed резервы', function (): void {
    $finance = app(FinancialReservationRepository::class);
    $finance->configureAccount('fixture-account', 'USD', 2, '0.015', 'fixture-operator', 'bounded exposure');
    $requests = app(RequestRepository::class);
    $a = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('one'), LedgerFixtures::uuid()->value, 1, $this->period));
    $b = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('two'), LedgerFixtures::uuid()->value, 1, $this->period));
    expect(fn () => DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('over'), LedgerFixtures::uuid()->value, 1, $this->period)))
        ->toThrow(OperationDenied::class, 'account_exposure_hold');
    FinancialFixtures::claim($a);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($a->physicalCallId)));
    expect(FinancialFixtures::claim($b))->toBeTrue();
    DB::transaction(fn () => $requests->interruptLocked($requests->get($b->physicalCallId)));
    $finance->configureAccount('fixture-account', 'USD', 2, null, 'fixture-operator', 'remove hard cap, retain count boundary');
    expect(fn () => DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('at-threshold'), LedgerFixtures::uuid()->value, 1, $this->period)))
        ->toThrow(OperationDenied::class, 'account_unknown_hold')
        ->and(DB::table('llm_financial_actions')->where('action', 'policy_change')->count())->toBe(3);
});

test('rollover не стирает old exposure а late receipt settles только original period', function (): void {
    $requests = app(RequestRepository::class);
    $operation = DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('rollover'), LedgerFixtures::uuid()->value, 1, $this->period));
    FinancialFixtures::claim($operation);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($operation->physicalCallId)));
    // Simulated passage across the financial boundary; immutable reservation still points to the old period.
    DB::statement("UPDATE llm_budget_periods SET period_end=clock_timestamp()-interval '1 second' WHERE id=?", [$this->period]);
    $end = DB::table('llm_budget_periods')->where('id', $this->period)->value('period_end');
    $next = app(FinancialReservationRepository::class)->openPeriod('fixture-account', 'USD', $end, '2027-01-01T00:00:00Z', '100');
    expect(fn () => DB::transaction(fn () => $requests->authorize(FinancialFixtures::genericPlan('new-day'), LedgerFixtures::uuid()->value, 1, $next)))
        ->toThrow(OperationDenied::class, 'account_unknown_hold');
    app(RequestLedger::class)->capture($requests->get($operation->physicalCallId), 200, FinancialFixtures::body());
    expect(DB::table('llm_budget_periods')->where('id', $this->period)->value('spent'))->toBe('0.00200001')
        ->and(DB::table('llm_budget_periods')->where('id', $this->period)->value('reserved'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $next)->value('spent'))->toBe('0.00000000')
        ->and(DB::table('llm_budget_periods')->where('id', $next)->value('reserved'))->toBe('0.00000000');
});

test('operator replay command дедуплицируется а два разных approved intents сохраняют старый unknown reserve', function (): void {
    $requests = app(RequestRepository::class);
    $originalPlan = FinancialFixtures::genericPlan('replay');
    $operation = DB::transaction(fn () => $requests->authorize($originalPlan, LedgerFixtures::uuid()->value, 1, $this->period));
    FinancialFixtures::claim($operation);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($operation->physicalCallId)));
    $original = $requests->get($operation->physicalCallId);
    $command = LedgerFixtures::uuid()->value;
    $a = app(RequestLedger::class)->replay($original, $command, 'fixture-operator', 'approve paid replay');
    expect(app(RequestLedger::class)->replay($original, $command, 'fixture-operator', 'approve paid replay'))->toEqual($a);
    $b = app(RequestLedger::class)->replay($original, LedgerFixtures::uuid()->value, 'fixture-operator', 'second distinct approval');
    expect($a->logicalKey)->not->toBe($b->logicalKey)->and($a->logicalKey)->not->toBe($originalPlan->identity->logicalKey);
    app(FinancialReservationRepository::class)->configureAccount('fixture-account', 'USD', 3, null, 'fixture-operator', 'allow explicit additional exposure');
    foreach ([$a, $b] as $identity) {
        $plan = new ProviderOperationPlan($identity, $originalPlan->context, $originalPlan->pricing, $originalPlan->consumerCorrelationId);
        DB::transaction(fn () => $requests->authorize($plan, LedgerFixtures::uuid()->value, 1, $this->period));
    }
    expect(DB::table('llm_provider_requests')->count())->toBe(3)->and(DB::table('llm_budget_reservations')->count())->toBe(3)
        ->and(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('state'))->toBe('reserved');
    $requests->abandon($original, 'fixture-operator', 'abandon unknown without forgiveness');
    expect(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('state'))->toBe('reserved');
    $requests->abandon($original, 'fixture-operator', 'conservatively charge upper bound', true);
    expect(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('settled_amount'))->toBe($original->upperBound);
    app(RequestLedger::class)->capture($requests->get($operation->physicalCallId), 200, FinancialFixtures::body());
    expect(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->value('settled_amount'))->toBe('0.00200001')
        ->and(DB::table('llm_financial_actions')->where('action', 'receipt_reconciliation')->count())->toBe(1);
});

test('pinned pricing неизвестная устаревшая или недостаточная не создаёт free reserve', function (): void {
    $plan = FinancialFixtures::genericPlan('price');
    $context = new PinnedLlmContext('fixture-provider', 'fixture-account', 'fixture-api/1', 'fixture-model', 'route/1', ['max_output_tokens' => 99999], []);
    expect(fn () => new ProviderOperationPlan($plan->identity, $context, $plan->pricing, 'fixture'))
        ->toThrow(InvalidArgumentException::class, 'Output bound');
    expect($plan->pricing->upperBound)->toBe('0.00709600')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(0);
    $withThinkingInOutput = new PricingSnapshot('USD', 'fixture/2', OperationPurpose::Generate, 1000, 4096, 1000, true,
        1000000, 1000000, 1000000, 1000, 0, 0, 0, 'fixture bounds', '2027-01-01');
    expect($withThinkingInOutput->upperBound)->toBe('0.00609600');
    $obsolete = new ProviderOperationPlan($plan->identity, $plan->context, FinancialFixtures::pricing(validUntil: '2000-01-01'), 'fixture:price');
    expect(fn () => DB::transaction(fn () => app(RequestRepository::class)->authorize($obsolete, LedgerFixtures::uuid()->value, 1, $this->period)))
        ->toThrow(OperationDenied::class, 'pricing_or_period_expired');
    expect(DB::table('llm_budget_reservations')->count())->toBe(0);
});

test('cache create unknown не разрешает fallback generate с новой identity без accounted budget', function (): void {
    $requests = app(RequestRepository::class);
    $cachePlan = FinancialFixtures::genericPlan('cache-fallback', OperationPurpose::CacheCreate);
    $generationPlan = FinancialFixtures::genericPlan('cache-fallback', OperationPurpose::Generate);
    $cache = DB::transaction(fn () => $requests->authorize($cachePlan, LedgerFixtures::uuid()->value, 1, $this->period));
    FinancialFixtures::claim($cache);
    DB::transaction(fn () => $requests->interruptLocked($requests->get($cache->physicalCallId)));
    expect($cachePlan->identity->logicalKey)->not->toBe($generationPlan->identity->logicalKey)
        ->and(fn () => DB::transaction(fn () => $requests->authorize($generationPlan, LedgerFixtures::uuid()->value, 1, $this->period)))
        ->toThrow(OperationDenied::class, 'account_unknown_hold')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->value('state'))->toBe('reserved');
});

test('physical retry budget durable и новая доставка или plan не обнуляет exhausted attempts', function (): void {
    $requests = app(RequestRepository::class);
    $plan = FinancialFixtures::genericPlan('physical-budget');
    for ($n = 0; $n < 3; $n++) {
        $operation = DB::transaction(fn () => $requests->authorize($plan, LedgerFixtures::uuid()->value, 1, $this->period));
        DB::transaction(fn () => $requests->interruptLocked($requests->get($operation->physicalCallId)));
    }
    expect(fn () => DB::transaction(fn () => $requests->authorize($plan, LedgerFixtures::uuid()->value, 1, $this->period)))
        ->toThrow(OperationDenied::class, 'physical_attempt_budget_exhausted');
    $changed = new ProviderOperationPlan($plan->identity, $plan->context, $plan->pricing, $plan->consumerCorrelationId, 10);
    expect(fn () => DB::transaction(fn () => $requests->authorize($changed, LedgerFixtures::uuid()->value, 1, $this->period)))
        ->toThrow(OperationDenied::class, 'physical_budget_policy_drift')
        ->and(DB::table('llm_provider_calls')->count())->toBe(3)
        ->and(DB::table('llm_budget_reservations')->where('state', 'settled')->count())->toBe(3)
        ->and(DB::table('llm_budget_periods')->value('reserved'))->toBe('0.00000000');
});
