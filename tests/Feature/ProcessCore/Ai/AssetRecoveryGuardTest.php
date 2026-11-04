<?php

declare(strict_types=1);

use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Cache\ProviderAssetManager;
use App\Services\Procurement\Extraction\AnalysisProviderRecoveryResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\FinancialFixtures;

pest()->group('provider-asset-lifecycle', 'provider-and-worker-recovery', 'analysis-execution');

test('restore отклоняет cache_storage receipt тем же успешным HTTP asset контрактом что и prepareRecovery', function (): void {
    $period = FinancialFixtures::reset();
    [, , $window, , $auth] = FinancialFixtures::authorized($period, OperationPurpose::CacheStorage);
    $call = app(RequestRepository::class)->get($auth->physicalCallId);
    $receipt = new VerifiedReceipt(
        $call,
        hash('sha256', '{"name":"cachedContents/fixture-storage"}'),
        200,
        '{"name":"cachedContents/fixture-storage"}',
        new ReceiptAssessment(ReceiptOutcome::Success, '0', 'fixture-policy/1:cache_storage:200'),
    );
    $key = new FileAssetKey($window->run->recipe->llm, hash('sha256', 'fixture-bytes'), 'application/pdf');

    expect(fn () => app(ProviderAssetManager::class)->prepareRecovery($receipt))
        ->toThrow(OperationDenied::class, 'successful_asset_receipt_required')
        ->and(fn () => app(ProviderAssetManager::class)->restore($key, $receipt))
        ->toThrow(OperationDenied::class, 'successful_asset_receipt_required');
});

test('settleLocked перечитывает физический вызов под локом и отвергает конкурентно claimed операцию без receipt', function (): void {
    $period = FinancialFixtures::reset();
    [$work, , , , $auth] = FinancialFixtures::authorized($period);
    $resolver = app(AnalysisProviderRecoveryResolver::class);
    $plan = $resolver->inspect($work, null);

    expect($plan?->mode)->toBe(ProviderRecoveryMode::KnownRetryEligible);

    expect(FinancialFixtures::claim($auth))->toBeTrue();

    expect(fn () => DB::transaction(fn () => $resolver->settleLocked($plan)))
        ->toThrow(LogicException::class, 'Settlement requires verified operation evidence.')
        ->and(DB::table('llm_provider_calls')->where('physical_call_id', $auth->physicalCallId)->value('receipt_state'))
        ->toBe('pending')
        ->and(DB::table('llm_budget_reservations')->where('id', $auth->reservationId)->value('state'))
        ->toBe('reserved');
});
