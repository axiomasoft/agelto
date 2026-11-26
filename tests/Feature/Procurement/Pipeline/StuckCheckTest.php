<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\StoreWorkData;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Llm\LlmRoute;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Estimate\Estimate;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\TelegramOutbound;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

beforeEach(function () {
    FinancialFixtures::reset();
    PermitFixtures::clean();
    ScreeningRuntimeFixtures::bind();
    foreach ([StageCode::Screening, StageCode::Extraction] as $stage) {
        app(ProcessAuthoritySync::class)->registerHandler($stage,
            $stage === StageCode::Screening ? ScreeningRuntimeFixtures::VERSION : ExtractionRuntimeFixtures::VERSION);
    }
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.chat_id' => '123456',
        'process.diagnostic_thresholds.analysis_stuck_minutes' => 30,
        'process.diagnostic_thresholds.checkpoint_stuck_minutes' => 60,
        'process.diagnostic_thresholds.document_stuck_minutes' => 60,
        'process.diagnostic_thresholds.spike_window_hours' => 24,
        'process.diagnostic_thresholds.unknown_spike_threshold' => 2,
        'process.diagnostic_thresholds.unprocessable_spike_threshold' => 2,
        'process.diagnostic_thresholds.estimate_mismatch_threshold' => 2,
        'process.diagnostic_thresholds.alert_antispam_minutes' => 60,
        'ai.model_eol_warn_days' => 30,
    ]);

    $this->telegramWire = new WireFixture;
    new PermitFixtures($this->telegramWire);
    $origin = $this->telegramWire->url('/')->origin;
    $profile = TransportFixtures::profile([
        'code' => 'notification.telegram',
        'attempts' => 1,
        'distinct_routes' => 1,
        'delays_ms' => [],
        'redirects' => 0,
    ]);
    app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(
        providerKey: new ProviderKey('telegram'),
        targets: [new SourceTargetDefinition('provider:telegram:notification', 'notification', $origin, [$profile])],
    ));
    HistoricalNetworkFixtures::synchronize();
    ProxymaCallerFixtures::install($this->telegramWire);
    config(['services.telegram.api_url' => $origin->value()]);
    app()->instance(TelegramOutbound::class, new TelegramOutbound($this->telegramWire->policy()));
    $this->telegramWire->respond(path: '/bottest-token/sendMessage', body: '{"ok":true}', method: 'POST');
});

afterEach(function () {
    if (isset($this->telegramWire)) {
        $this->telegramWire->stop();
    }
});

/** A real claimed canonical work; only the DB clock position is controlled by the fixture. */
function stuckAnalysisFixture(AnalysisStep $step, bool $expired, int $ageMinutes): void
{
    $window = AiFixtures::window();
    // Isolate the work-lease detector from the retained document-backlog diagnostic.
    DB::table('prc_documents')->where('current_version_id', $window->run->documentVersionId)
        ->update(['status' => DocumentStatus::Extracted->value]);
    $input = $step === AnalysisStep::Screening
        ? new ScreeningInput($window->run->documentVersionId, $window->run->representationHash, $window->run->recipe->hash)
        : new ExtractionWindowInput($window->run->id, $window->windowKey, $window->run->recipe->hash);
    $id = new WorkId(LedgerFixtures::uuid());
    app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork(new StoreWorkData(
        id: $id,
        input: $input,
        inputRevision: $window->run->expectedAnalysisGeneration,
        intentId: null,
        cohortKey: 'manual',
        platformId: null,
        originRunId: null,
        priority: 100,
        eventId: LedgerFixtures::uuid(),
        projectionMessageId: LedgerFixtures::uuid(),
    )));
    FinalizeFixtures::running($id);
    DB::update("UPDATE prc_process_work SET lease_until=clock_timestamp()+(? * interval '1 minute'),updated_at=clock_timestamp()-(? * interval '1 minute') WHERE id=?",
        [$expired ? -1 : 5, $ageMinutes, $id->value->value]);
}

test('без аномалий команда завершается тихо, без Telegram-алерта', function () {
    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('зависший screening-анализ (running, лиз истёк, старше порога) — алерт', function () {
    stuckAnalysisFixture(AnalysisStep::Screening, true, 31);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('running screening-анализ моложе порога НЕ считается зависшим', function () {
    stuckAnalysisFixture(AnalysisStep::Screening, true, 5);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('running screening-анализ с ДЕЙСТВУЮЩИМ лизом НЕ считается зависшим', function () {
    stuckAnalysisFixture(AnalysisStep::Screening, false, 31);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('зависший extraction-анализ использует СВОЙ (больший) порог checkpoint_stuck_minutes', function () {
    // 31 минута — зависший screening-порог, НО меньше extraction-порога (60) — не алертит.
    stuckAnalysisFixture(AnalysisStep::Extraction, true, 31);

    $this->artisan('process:diagnostics')->assertSuccessful();
    expect($this->telegramWire->receipts())->toBeEmpty();

    stuckAnalysisFixture(AnalysisStep::Extraction, true, 61);

    $this->artisan('process:diagnostics')->assertSuccessful();
    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('документ, застрявший в prepared сверх порога, — алерт; failed_permanently исключён', function () {
    Document::factory()->create([
        'status' => DocumentStatus::Prepared,
        'failed_permanently' => false,
        'updated_at' => now()->subMinutes(61),
    ]);

    Document::factory()->create([
        'status' => DocumentStatus::Prepared,
        'failed_permanently' => true,
        'updated_at' => now()->subMinutes(61),
        'attempts' => 5,
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

// Проверяет обнаружение неподвижных документов.
// документ с провалом СКАЧИВАНИЯ был невидим для единственного канала, который сам приходит к
// человеку (Telegram PipelineAlert).
test('документ, застрявший в discovered сверх порога (провал скачивания), — алерт (Д-5)', function () {
    Document::factory()->create([
        'status' => DocumentStatus::Discovered,
        'failed_permanently' => false,
        'attempts' => 1,
        'updated_at' => now()->subMinutes(61),
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('expanded archive не считается зависшей стадией', function () {
    Document::factory()->create([
        'status' => DocumentStatus::Expanded,
        'updated_at' => now()->subMinutes(61),
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('всплеск unknown в скрининге сверх порога — алерт', function () {
    Analysis::factory()->count(3)->create([
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'updated_at' => now()->subHour(),
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('unknown-скрининги ниже порога не алертят', function () {
    Analysis::factory()->count(2)->create([
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'updated_at' => now()->subHour(),
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('всплеск unprocessable за окно — алерт', function () {
    Document::factory()->count(3)->create([
        'status' => DocumentStatus::Unprocessable,
        'last_error' => 'unprocessable:too_large: 30000 строк',
        'updated_at' => now()->subHour(),
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('всплеск suspicious/invalid смет за окно — алерт', function () {
    Estimate::factory()->count(3)->create([
        'validation_status' => EstimateValidationStatus::Invalid->value,
        'updated_at' => now()->subHour(),
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('модель активного маршрута ближе порога к eol_date — алерт с преемником', function () {
    $this->travelTo(now()->startOfDay());
    config([
        'ai.capabilities.eol-soon-model' => [
            'eol_date' => now()->addDays(10)->toDateString(),
            'successor' => 'eol-soon-model-v2',
        ],
    ]);

    LlmRoute::factory()->create([
        'model' => 'eol-soon-model',
        'is_active' => true,
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    $expectedPayload = json_encode([
        'chat_id' => '123456',
        'text' => implode("\n", [
            '⚠️ <b>[AGELTO [TESTING]] Проблемы пайплайна</b>',
            '',
            '• Модели активных маршрутов близки к EOL: eol-soon-model (осталось 10д, преемник eol-soon-model-v2)',
            '',
            'Проверьте Horizon или запустите <code>php artisan process:status</code> / <code>php artisan pipeline:status</code>',
        ]),
        'parse_mode' => 'HTML',
    ], JSON_THROW_ON_ERROR);

    $receipts = $this->telegramWire->receipts();
    expect($receipts)->toHaveCount(1)
        ->and($receipts[0]['body_sha256'])->toBe(hash('sha256', $expectedPayload));
});

test('модель НЕактивного маршрута близко к eol_date не алертит', function () {
    config([
        'ai.capabilities.eol-soon-model' => [
            'eol_date' => now()->addDays(10)->toDateString(),
            'successor' => 'eol-soon-model-v2',
        ],
    ]);

    LlmRoute::factory()->create([
        'model' => 'eol-soon-model',
        'is_active' => false,
    ]);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('антиспам: повторный тик в течение окна не дублирует Telegram-сообщение по той же находке', function () {
    stuckAnalysisFixture(AnalysisStep::Screening, true, 31);

    $this->artisan('process:diagnostics')->assertSuccessful();
    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toHaveCount(1);
});

test('без Telegram-конфига алерт не падает, а тихо уходит в лог', function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
    stuckAnalysisFixture(AnalysisStep::Screening, true, 31);

    $this->artisan('process:diagnostics')->assertSuccessful();

    expect($this->telegramWire->receipts())->toBeEmpty();
});

test('pipeline:status отрабатывает без ошибок и показывает счётчики документов/анализов', function () {
    Document::factory()->create(['status' => DocumentStatus::Extracted]);
    Analysis::factory()->create(['step' => AnalysisStep::Screening, 'status' => AnalysisStatus::Succeeded]);
    Document::factory()->create([
        'status' => DocumentStatus::Unprocessable,
        'last_error' => 'unprocessable:too_large: деталь',
    ]);
    Analysis::factory()->create([
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'result' => [
            'schema_version' => 2,
            'screening' => [
                'confidence' => 0.5,
                'summary' => 'Пограничная классификация',
                'estimate_cardinality' => 'unknown',
                'tags' => [],
                'classification_note' => null,
                'page_count_estimate' => null,
            ],
            'decision' => [
                'disposition' => 'review',
                'reason' => 'borderline_confidence',
                'extraction_dispatched' => false,
            ],
        ],
    ]);

    $this->artisan('pipeline:status')
        ->expectsOutputToContain('Borderline-скринингов (ждут оператора): 1')
        ->assertSuccessful();
});
