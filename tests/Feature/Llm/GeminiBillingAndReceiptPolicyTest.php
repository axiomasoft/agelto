<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PricingSnapshot;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\LlmSpendReporter;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('provider-financial-ledger');

beforeEach(function (): void {
    $this->period = FinancialFixtures::reset();
    app(ReceiptPolicies::class)->register(provider: 'fixture-provider', policy: new GeminiAssetReceiptPolicy);
});

test('Gemini списывает использованные токены по сохранённому тарифу и освобождает остаток резерва', function (bool $sharedOutputRate, string $cost, int $micros): void {
    $base = FinancialFixtures::genericPlan('gemini-actual');
    $pricing = new PricingSnapshot(currency: 'USD', version: 'fixture-actual/1', purpose: OperationPurpose::Generate, inputMaxTokens: 1000,
        outputMaxTokens: 4096, thinkingMaxTokens: 1000, thinkingIncludedInOutput: $sharedOutputRate, inputPerMillion: 1_000_001,
        outputPerMillion: 2_000_000, thinkingPerMillion: 3_000_000, fixedCharge: 1000, cacheCreateCharge: 0,
        storageTokenHoursMax: 0, storagePerTokenHour: 0, boundEvidence: 'fixture maxima', validUntil: $base->pricing->validUntil);
    $plan = new ProviderOperationPlan(identity: $base->identity, context: $base->context, pricing: $pricing, consumerCorrelationId: $base->consumerCorrelationId);
    $requests = app(RequestRepository::class);
    $operation = DB::transaction(fn () => $requests->authorize(plan: $plan, attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period));
    expect(FinancialFixtures::claim($operation))->toBeTrue();

    $body = json_encode(['candidates' => [['finishReason' => 'STOP']], 'usageMetadata' => [
        'promptTokenCount' => 100, 'cachedContentTokenCount' => 20, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 10,
    ]], JSON_THROW_ON_ERROR);
    app(RequestLedger::class)->capture(call: $requests->get($operation->physicalCallId), status: 200, body: $body);

    $reader = app(LlmCallReadRepository::class);
    $call = $reader->query()->sole();
    $usage = $reader->usageByAxes(from: CarbonImmutable::now()->subDay(), to: CarbonImmutable::now()->addDay(), axes: []);
    expect($call->cost_actual)->toBe($cost)
        ->and($call->getAttribute('thinking_tokens'))->toBe(10)
        ->and($usage[0]['thinking_tokens'])->toBe(10)
        ->and($usage[0]['cached_tokens'])->toBe(20)
        ->and($usage[0]['cost_micros'])->toBe($micros)
        ->and(DB::table('llm_budget_reservations')->soleValue('settled_amount'))->toBe($cost)
        ->and(DB::table('llm_budget_periods')->soleValue('spent'))->toBe($cost)
        ->and(DB::table('llm_budget_periods')->soleValue('reserved'))->toBe('0.00000000');
})->with([
    'отдельный тариф размышлений' => [false, '0.00117100', 1171],
    'размышления по тарифу вывода' => [true, '0.00116100', 1161],
]);

test('доказанный бесплатный ответ освобождает резерв и допускает следующий вызов', function (OperationPurpose $purpose, int $status, string $body, ReceiptOutcome $outcome): void {
    $requests = app(RequestRepository::class);
    $operation = DB::transaction(fn () => $requests->authorize(plan: FinancialFixtures::genericPlan(seed: 'gemini-error', purpose: $purpose),
        attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period));
    expect(FinancialFixtures::claim($operation))->toBeTrue();
    $receipt = app(RequestLedger::class)->capture(call: $requests->get($operation->physicalCallId), status: $status, body: $body);
    $next = DB::transaction(fn () => $requests->authorize(plan: FinancialFixtures::genericPlan('gemini-after-error'),
        attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period));

    expect($receipt->assessment->outcome)->toBe($outcome)
        ->and($requests->get($operation->physicalCallId)->requestState)->toBe('failed_known')
        ->and($requests->get($operation->physicalCallId)->recordedAssessment?->cost)->toBe('0.00000000')
        ->and(DB::table('llm_budget_reservations')->where('id', $operation->reservationId)->soleValue('settled_amount'))->toBe('0.00000000')
        ->and(FinancialFixtures::claim($next))->toBeTrue();
})->with([
    '404 при поиске ресурса' => [OperationPurpose::Lookup, 404, '{}', ReceiptOutcome::NonbillableRetry],
    '404 для отсутствующего cachedContent' => [OperationPurpose::Generate, 404, '{"error":{"code":404,"message":"CachedContent not found"}}', ReceiptOutcome::NonbillableCacheMiss],
    '429 при ограничении частоты' => [OperationPurpose::Generate, 429, '{"error":{"code":429,"status":"RESOURCE_EXHAUSTED"}}', ReceiptOutcome::NonbillableRetry],
    '503 при недоступности' => [OperationPurpose::Generate, 503, '{"error":{"code":503,"status":"UNAVAILABLE"}}', ReceiptOutcome::NonbillableRetry],
    '400 при ошибке запроса' => [OperationPurpose::Generate, 400, '{"error":{"code":400,"status":"INVALID_ARGUMENT"}}', ReceiptOutcome::NonbillableRetry],
]);

test('отказ Gemini сохраняет подтверждённый расход и не превращает неполную квитанцию в бесплатный повтор', function (): void {
    $requests = app(RequestRepository::class);
    $operation = DB::transaction(fn () => $requests->authorize(plan: FinancialFixtures::genericPlan('gemini-refusal'),
        attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period));
    expect(FinancialFixtures::claim($operation))->toBeTrue();

    $receipt = app(RequestLedger::class)->capture(call: $requests->get($operation->physicalCallId), status: 200,
        body: '{"promptFeedback":{"blockReason":"SAFETY"},"usageMetadata":{"promptTokenCount":100}}');
    $policy = new GeminiAssetReceiptPolicy;
    $missingOutput = $policy->assess(purpose: OperationPurpose::Generate, httpStatus: 200, body: '{"candidates":[],"usageMetadata":{"promptTokenCount":100}}');
    $generic404 = $policy->assess(purpose: OperationPurpose::Generate, httpStatus: 404, body: '{"error":{"code":404,"message":"Model not found"}}');
    $billedError = $policy->assess(purpose: OperationPurpose::Generate, httpStatus: 400,
        body: '{"error":{"code":400},"usageMetadata":{"promptTokenCount":100,"candidatesTokenCount":10}}');
    expect($receipt->assessment->outcome)->toBe(ReceiptOutcome::Success)
        ->and($receipt->assessment->usage?->output)->toBe(0)
        ->and($requests->get($operation->physicalCallId)->recordedAssessment?->cost)->toBe('0.00110000')
        ->and($missingOutput->outcome)->toBe(ReceiptOutcome::Unknown)
        ->and($generic404->outcome)->toBe(ReceiptOutcome::Unknown)
        ->and($billedError->outcome)->toBe(ReceiptOutcome::BilledError);
});

test('квитанция с перерасходом сохраняется один раз и сокращает доступный бюджет периода', function (bool $conservative): void {
    DB::table('llm_budget_periods')->where('id', $this->period)->update(['cap' => '0.01500000']);
    $requests = app(RequestRepository::class);
    $operation = DB::transaction(fn () => $requests->authorize(plan: FinancialFixtures::genericPlan('gemini-overdraft'),
        attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period));
    expect(FinancialFixtures::claim($operation))->toBeTrue();
    $call = $requests->get($operation->physicalCallId);
    expect(fn () => DB::transaction(fn () => app(FinancialReservationRepository::class)->settleLocked(call: $call, amount: '0.00903000')))
        ->toThrow(OperationDenied::class, 'usage_exceeds_reserved_bound');
    if ($conservative) {
        DB::transaction(fn () => $requests->interruptLocked($call));
        $requests->abandon(expected: $call, actor: 'fixture', reason: 'учесть резерв до поздней квитанции', conservativelySettle: true);
    }

    $receipt = app(RequestLedger::class)->capture(call: $call, status: 200,
        body: '{"candidates":[],"usageMetadata":{"promptTokenCount":8000,"candidatesTokenCount":20,"thoughtsTokenCount":10}}');
    DB::transaction(fn () => $requests->acceptReceiptLocked($receipt));
    $recorded = $requests->get($operation->physicalCallId);
    $details = DB::table('llm_financial_actions')->where('action', 'receipt_reconciliation')->soleValue('details');
    expect($recorded->requestState)->toBe('completed')
        ->and($recorded->receiptHash)->toBe($receipt->hash)
        ->and(app(ReceiptStore::class)->inspect($recorded)?->assessment->cost)->toBe('0.00903000')
        ->and(json_decode($details, true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'receipt_sha256' => $receipt->hash, 'actual_amount' => '0.00903000', 'overdraft_amount' => '0.00193400',
        ])
        ->and(DB::table('llm_budget_periods')->soleValue('reserved'))->toBe('0.00000000')
        ->and(fn () => DB::transaction(fn () => $requests->authorize(plan: FinancialFixtures::genericPlan('gemini-after-overdraft'),
            attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period)))
        ->toThrow(OperationDenied::class, 'provider_budget');
})->with(['активный резерв' => false, 'консервативно закрытый резерв' => true]);

test('отложенный вызов сохраняет обязательство до консервативного списания и уточняется поздней квитанцией', function (): void {
    $finance = app(FinancialReservationRepository::class);
    $requests = app(RequestRepository::class);
    $authorize = fn (string $seed) => DB::transaction(fn () => $requests->authorize(plan: FinancialFixtures::genericPlan($seed),
        attempt: LedgerFixtures::uuid()->value, generation: 1, periodId: $this->period));
    $report = fn () => app(LlmSpendReporter::class)->report(from: CarbonImmutable::now()->subDay(), to: CarbonImmutable::now()->addDay());

    $operation = $authorize('gemini-abandoned');
    expect(FinancialFixtures::claim($operation))->toBeTrue();
    $call = $requests->get($operation->physicalCallId);
    DB::transaction(fn () => $requests->interruptLocked($call));
    $requests->abandon(expected: $call, actor: 'fixture', reason: 'отложить неизвестный вызов');
    $finance->configureAccount(account: 'fixture-account', currency: 'USD', threshold: 2, hardExposure: '0.008', actor: 'fixture', reason: 'проверить открытое обязательство');
    expect($report()->reservedMicros)->toBe(7096)
        ->and($report()->unknownCalls)->toBe(1)
        ->and(fn () => $authorize('gemini-abandoned-still-open'))->toThrow(OperationDenied::class, 'account_exposure_hold');

    $requests->abandon(expected: $call, actor: 'fixture', reason: 'списать резерв', conservativelySettle: true);
    $finance->configureAccount(account: 'fixture-account', currency: 'USD', threshold: 1, hardExposure: '0.008', actor: 'fixture', reason: 'проверить закрытое обязательство');
    expect($requests->get($operation->physicalCallId)->requestState)->toBe('abandoned_unknown')
        ->and($report()->totalCostMicros)->toBe(7096)
        ->and($report()->reservedMicros)->toBe(0)
        ->and($report()->unknownCalls)->toBe(1)
        ->and(FinancialFixtures::claim($authorize('gemini-abandoned-now-closed')))->toBeTrue();

    app(RequestLedger::class)->capture(call: $call, status: 200,
        body: '{"candidates":[],"usageMetadata":{"promptTokenCount":100,"candidatesTokenCount":20}}');
    expect($requests->get($operation->physicalCallId)->requestState)->toBe('completed')
        ->and($report()->totalCostMicros)->toBe(1120)
        ->and(DB::table('llm_budget_periods')->soleValue('spent'))->toBe('0.00112000');
});

test('повторная авторизация возвращает только ещё не захваченный вызов', function (): void {
    $requests = app(RequestRepository::class);
    $plan = FinancialFixtures::genericPlan('gemini-duplicate-authorization');
    $attempt = LedgerFixtures::uuid()->value;
    $authorize = fn () => DB::transaction(fn () => $requests->authorize(plan: $plan, attempt: $attempt, generation: 1, periodId: $this->period));
    $operation = $authorize();
    expect($authorize())->toEqual($operation)
        ->and(FinancialFixtures::claim($operation))->toBeTrue()
        ->and($authorize)->toThrow(OperationDenied::class, 'already_claimed')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(1);
});
