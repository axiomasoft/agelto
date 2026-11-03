<?php

declare(strict_types=1);

use App\Console\Commands\Process\ProcessDiagnosticsCommand;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Models\Procurement\Document\Document;
use App\Pulse\Cards\LlmSpendCard;
use App\Pulse\Cards\PipelineStagesCard;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProviderReportFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

beforeEach(fn () => ProviderReportFixtures::reset());

test('PipelineStagesCard считает succeeded/failed по стадиям prepare/screening/extraction за 24ч', function () {
    [$first,$lease,$window,$period] = ScreeningRuntimeFixtures::work();
    foreach ([0, 1, 2] as $index) {
        if ($index !== 0) {
            $window = AiFixtures::window();
            $first = new WorkId(LedgerFixtures::uuid());
            $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
            app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork(
                $first, $input, $window->run->expectedAnalysisGeneration, null, 'manual', null, null, 100,
                LedgerFixtures::uuid(), LedgerFixtures::uuid()));
            $lease = FinalizeFixtures::running($first);
        }
        $finalizer = app(WorkFinalizer::class);
        if ($index === 2) {
            $failure = new StageFailedItem(ItemFailureReason::ContractRejected,
                AiFixtures::output('fixture failure evidence'));
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $finalizer->finish($lease, $failure);
                if ($attempt < 2) {
                    $lease = FinalizeFixtures::rerun($first);
                }
            }

            continue;
        }
        $raw = json_decode(ScreeningRuntimeFixtures::raw(), true, flags: JSON_THROW_ON_ERROR);
        $raw['document_type'] = 'tech_spec';
        $raw['estimate_cardinality'] = 'none';
        ScreeningRuntimeFixtures::capture($lease, $window, $period, json_encode($raw, JSON_THROW_ON_ERROR));
        $input = new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash);
        for ($slice = 0; $slice < 2; $slice++) {
            $finalizer->finish($lease, app(ScreeningHandler::class)->execute($input, app(ExecutionContextFactory::class)->open($lease)));
            if ($slice === 0) {
                $lease = FinalizeFixtures::running($first);
            }
        }
    }
    // The retained preparation card remains independent from the AI result projection.
    // prepare: 1 успех (покинул downloaded) + 1 провал (failed_permanently)
    Document::factory()->create(['status' => DocumentStatus::Prepared, 'failed_permanently' => false]);
    Document::factory()->create(['status' => DocumentStatus::Downloaded, 'failed_permanently' => true]);
    // документ вне окна (обновлён «вчера») — не считается
    Document::factory()->create(['status' => DocumentStatus::Prepared, 'updated_at' => now()->subDays(2)]);

    $card = new PipelineStagesCard;
    $view = $card->render();
    $stages = $view->getData()['stages']->keyBy('stage');

    expect($stages['prepare']['succeeded'])->toBe(1)
        ->and($stages['prepare']['failed'])->toBe(1)
        ->and($stages['screening']['succeeded'])->toBe(2)
        ->and($stages['screening']['failed'])->toBe(1)
        ->and($stages['screening']['failure_rate'])->toBe(33.3)
        ->and($stages['extraction']['succeeded'])->toBe(0)
        ->and($stages['extraction']['failed'])->toBe(0);

    test()->artisan('pipeline:status')->expectsOutputToContain('AI work (prc_process_work)')->assertSuccessful();
    config(['process.diagnostic_thresholds.failure_rate_threshold_percent' => 30.0]);
    // Exercise the read-only detector without invoking the command's external notification sink.
    $method = new ReflectionMethod(ProcessDiagnosticsCommand::class, 'failureRateSpike');
    $findings = $method->invoke(app(ProcessDiagnosticsCommand::class));
    expect($findings['failure_rate'])->toContain('33.3%', '(1/3,');
});

test('LlmSpendCard группирует токены/стоимость provider ledger по purpose за 24ч', function () {
    ProviderReportFixtures::store([
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 1000,
        'cached_tokens' => 0,
        'completion_tokens' => 100,
        'cost_micros' => 500,
        'called_at' => now(),
    ]);
    ProviderReportFixtures::store([
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 500,
        'cached_tokens' => 0,
        'completion_tokens' => 50,
        'cost_micros' => 250,
        'called_at' => now(),
    ]);
    // вне окна — не учитывается
    ProviderReportFixtures::store([
        'purpose' => CoreLlmPurpose::Screening->value,
        'cost_micros' => 999_999,
        'called_at' => now()->subDays(3),
    ]);

    $card = new LlmSpendCard;
    $view = $card->render();
    $data = $view->getData();
    $rows = $data['rows']->keyBy('purpose');

    expect($rows['generate']->calls)->toBe(2)
        ->and($rows['generate']->promptTokens)->toBe(1500)
        ->and($rows['generate']->costMicros)->toBe(750)
        ->and($data['totalCostMicros'])->toBe(750);
});

test('LlmSpendCard подписывает цифру источником — «наш трекинг» с лагом и горизонтом', function () {
    $card = new LlmSpendCard;
    $signature = $card->render()->getData()['signature'];

    expect($signature)->toContain('provider ledger')
        ->and($signature)->toContain('лаг 0')
        ->and($signature)->toContain('без удаления по возрасту');
});
