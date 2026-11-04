<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Exceptions\Llm\BillingExportUnreadable;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\BillingReconciler;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageReport;
use App\Services\Llm\Dto\UsageSignature;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
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
