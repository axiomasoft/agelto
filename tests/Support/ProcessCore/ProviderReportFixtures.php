<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Llm\Requests\ProviderUsage;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Models\Llm\LlmCall;
use App\Models\Llm\Provider;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptPolicy;
use App\Services\Llm\Execution\RequestLedger;
use Illuminate\Support\Facades\DB;

/** Reporting fixtures only: real canonical request/call/reservation/receipt rows, zero HTTP. */
final class ProviderReportFixtures implements ReceiptPolicy
{
    public static function reset(): void
    {
        StoredDocumentFixtures::reset();
        DB::statement('TRUNCATE llm_provider_requests,llm_provider_calls,llm_budget_reservations,llm_budget_periods,llm_budget_accounts CASCADE');
    }

    /** @param array<string,mixed> $attributes */
    public static function store(array $attributes = []): LlmCall
    {
        $provider = $attributes['provider'] ?? 'gemini';
        Provider::query()->firstOrCreate(['code' => $provider], ['name' => 'Fixture report provider', 'driver' => 'gemini', 'is_active' => true]);
        app(ReceiptPolicies::class)->register($provider, new self);
        $account = 'report-fixture:'.$provider;
        $budget = app(FinancialReservationRepository::class);
        $budget->configureAccount($account, 'USD', 1, null, 'fixture', 'Reporting facts only');
        $period = $budget->openPeriod($account, 'USD', '2020-01-01T00:00:00Z', '2099-01-01T00:00:00Z', '9999999');
        $context = new PinnedLlmContext($provider, $account, 'fixture/1', $attributes['model'] ?? 'fixture-model', 'fixture/1', ['max_output_tokens' => 4096], []);
        $purpose = OperationPurpose::tryFrom($attributes['purpose'] ?? 'generate') ?? OperationPurpose::Generate;
        $seed = LedgerFixtures::uuid()->value;
        $plan = new ProviderOperationPlan(new RequestIdentity(hash('sha256', 'report-fixture'), hash('sha256', $seed), $purpose, 0, $seed),
            $context, FinancialFixtures::pricing($purpose, fixed: 100_000_000, validUntil: '2099-01-01T00:00:00Z'), 'report:'.$seed);
        $ids = ProviderOperationIds::allocate();
        $ledger = app(RequestLedger::class);
        $op = DB::transaction(fn () => $ledger->authorize($plan, LedgerFixtures::uuid()->value, 1, $period, $ids));
        FinancialFixtures::claim($op);
        $cost = bcdiv((string) ($attributes['cost_micros'] ?? 1000), '1000000', 8);
        $body = json_encode(['cost' => $cost, 'input' => $attributes['prompt_tokens'] ?? 100, 'cached' => $attributes['cached_tokens'] ?? 0,
            'output' => $attributes['completion_tokens'] ?? 20], JSON_THROW_ON_ERROR);
        if (isset($attributes['called_at'])) {
            DB::table('llm_provider_calls')->where('physical_call_id', $op->physicalCallId)->update(['sent_at' => $attributes['called_at']]);
        }
        $ledger->capture(app(RequestRepository::class)->get($op->physicalCallId), 200, $body);

        return app(LlmCallReadRepository::class)->query()->where('physical_call_id', $op->physicalCallId)->sole();
    }

    public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment
    {
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return new ReceiptAssessment(ReceiptOutcome::Success, $data['cost'], 'deterministic-report-receipt',
            new ProviderUsage($data['input'], $data['cached'], $data['output'], 0, 0, false));
    }
}
