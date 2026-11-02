<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\AnalysisWindowSnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Llm\Provider;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptPolicy;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use Illuminate\Support\Facades\DB;

/** Only fixture response policy; deliberately never registered in application production wiring. */
final class FinancialFixtures implements ReceiptPolicy
{
    public static function reset(): string
    {
        StoredDocumentFixtures::reset();
        DB::statement('TRUNCATE llm_provider_requests,llm_provider_calls,llm_budget_reservations,llm_budget_periods,llm_budget_accounts CASCADE');
        Provider::query()->where('code', 'fixture-provider')->first() ?? Provider::factory()->create(['code' => 'fixture-provider', 'is_active' => true]);
        app(ReceiptPolicies::class)->register('fixture-provider', new self);
        ExecutionFixtures::authority([StageCode::Screening]);
        app(StageRegistry::class)->bindFinalization(StageCode::Screening, new FixtureStagePayloadCodec(StageCode::Screening), new FinancialFixturePublication);
        $budget = app(FinancialReservationRepository::class);
        $budget->configureAccount('fixture-account', 'USD', 1, null, 'fixture', 'default safe unknown policy');

        return $budget->openPeriod('fixture-account', 'USD', '2026-01-01T00:00:00Z', '2027-01-01T00:00:00Z', '100');
    }

    public static function pricing(OperationPurpose $purpose = OperationPurpose::Generate, int $fixed = 1000, string $validUntil = '2027-01-01T00:00:00Z'): PricingSnapshot
    {
        return new PricingSnapshot('USD', 'fixture-price/1', $purpose, 1000, 4096, 1000, false,
            1_000_000, 1_000_000, 1_000_000, $fixed, 0, 0, 0, 'fixture conservative context/output/thinking maxima', $validUntil);
    }

    public static function genericPlan(string $seed, OperationPurpose $purpose = OperationPurpose::Generate): ProviderOperationPlan
    {
        return new ProviderOperationPlan(new RequestIdentity(hash('sha256', 'fixture-recipe'), hash('sha256', $seed), $purpose, 0,
            '11111111-1111-4111-8111-111111111111'), AiFixtures::recipe()->llm, self::pricing($purpose), 'fixture:'.$seed);
    }

    /** @return array{WorkId,ExecutionLease,AnalysisWindowSnapshot,ProviderOperationPlan,AuthorizedProviderOperation} */
    public static function authorized(string $period, OperationPurpose $purpose = OperationPurpose::Generate): array
    {
        $window = AiFixtures::window();
        $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
        $work = new WorkId(LedgerFixtures::uuid());
        app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork($work, $input,
            $window->run->expectedDocumentHeadGeneration, null, 'manual', null, null, 100, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        $lease = FinalizeFixtures::running($work);
        $plan = self::plan($window, $lease, $purpose);
        $authorization = app(AnalysisCallCoordinator::class)->authorize($lease, $window->run->id, $plan, $period);

        return [$work, $lease, $window, $plan, $authorization];
    }

    public static function plan(AnalysisWindowSnapshot $window, ExecutionLease $lease, OperationPurpose $purpose = OperationPurpose::Generate): ProviderOperationPlan
    {
        return new ProviderOperationPlan(new RequestIdentity($window->run->recipe->hash->hex, $lease->inputHash->hex, $purpose, 0, $window->run->id->value),
            $window->run->recipe->llm, self::pricing($purpose), $lease->workId->value->value);
    }

    /** Fixture only: financial half committed before a simulated worker death, never permission for HTTP. */
    public static function claim(AuthorizedProviderOperation $operation): bool
    {
        return DB::transaction(fn () => app(RequestRepository::class)->claimFinancialLocked($operation));
    }

    public static function due(WorkId $work): void
    {
        DB::update("UPDATE prc_process_work SET provider_recovery_due_at=clock_timestamp()-interval '1 second' WHERE id=? AND state='blocked' AND reason_code='provider_result_unknown'", [$work->value->value]);
    }

    public static function body(string $outcome = 'success', ?string $cost = '0.00200001'): string
    {
        return json_encode(['fixture_receipt' => 1, 'outcome' => $outcome, 'cost' => $cost, 'raw' => AiFixtures::raw()], JSON_THROW_ON_ERROR);
    }

    public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment
    {
        $value = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || ($value['fixture_receipt'] ?? null) !== 1 || ! is_string($value['outcome'] ?? null)) {
            return new ReceiptAssessment(ReceiptOutcome::Unknown, null, 'fixture-policy/1:unproven');
        }
        $outcome = ReceiptOutcome::tryFrom($value['outcome']) ?? ReceiptOutcome::Unknown;
        $cost = $value['cost'] ?? null;
        if ($cost !== null && ! is_string($cost)) {
            throw new \InvalidArgumentException('Fixture cost must be exact.');
        }

        return new ReceiptAssessment($outcome, $cost, 'fixture-policy/1:'.$purpose->value.':'.$httpStatus);
    }
}
