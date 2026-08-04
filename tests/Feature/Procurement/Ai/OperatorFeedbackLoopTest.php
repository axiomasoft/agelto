<?php

declare(strict_types=1);

use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\CorrectionReason;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Extraction\RerunAnalysisAction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

use function Pest\Laravel\artisan;

// Операторский цикл (P9.2, D24/D15, Intent «rejected+коррекция → re-run → hint в промте, новый
// анализ ссылается на старый»). Хелперы фикстур зеркалят
// tests/Feature/Procurement/Extraction/ExtractionOrchestratorTest.php (P6.1) — файл-локальны,
// Pest не разделяет глобальные функции между тестовыми файлами без общего helper-файла.

function feedbackFixtureContent(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function seedFeedbackVersion(Document $document, string $fixture = 'sample-multi-page.pdf'): DocumentVersion
{
    $content = feedbackFixtureContent($fixture);
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => $fixture,
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);

    // Launch-точка боевого конвейера (§3.5 root/document-contour.md), см. ScreeningProcessorTest.
    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    return $version;
}

function seedFeedbackRoute(FakeLlmProvider $fake): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function feedbackStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 500, completionTokens: 50, cachedTokens: 0, totalTokens: 550),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function feedbackPassport(?string $estimateNumber, int $rowsExpected): LlmStructuredResponse
{
    return feedbackStructured([
        'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
        'estimate_number' => $estimateNumber,
        'currency' => 'RUB',
        'base_year' => null,
        'price_level' => null,
        'rows_expected' => $rowsExpected,
        'totals' => null,
    ]);
}

/**
 * @param  list<int>  $rowIndexes
 */
function feedbackRows(array $rowIndexes): LlmStructuredResponse
{
    $rows = array_map(static fn (int $index): array => [
        'row_index' => $index,
        'row_type' => 'item',
        'name' => "Позиция {$index}",
        'section_title' => null,
        'code' => null,
        'unit' => null,
        'quantity' => null,
        'unit_price' => null,
        'total_price' => null,
        'resources' => [],
        'modifiers' => [],
    ], $rowIndexes);

    return feedbackStructured(['rows' => $rows, 'incomplete_items' => []]);
}

function feedbackTotals(): LlmStructuredResponse
{
    return feedbackStructured(['totals' => ['footer' => [
        ['label' => 'Итого', 'value' => '100.00', 'kind' => null, 'children' => []],
    ]]]);
}

/**
 * Одна смета/одна позиция (rows_expected=1) — ровно 5 вызовов на прогон (паспорт → строка →
 * пустой диапазон-терминатор строк → итоги → паспорт-терминатор следующей сметы), тот же цикл,
 * что `ExtractionOrchestratorTest`.
 */
function queueOneEstimateRun(FakeLlmProvider $fake): FakeLlmProvider
{
    return $fake
        ->andThen(feedbackRows([1]))
        ->andThen(feedbackRows([]))
        ->andThen(feedbackTotals())
        ->andThen(feedbackPassport(null, 0));
}

test('отклонённый анализ с коррекцией → re-run подмешивает hint в USER-часть промта, новый анализ ссылается на старый, старые сметы заменяются', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(feedbackPassport('1', 1));
    queueOneEstimateRun($fake);

    seedFeedbackRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    $version = seedFeedbackVersion($document);

    // Вердикт скрининга — ЕДИНСТВЕННЫЙ носитель типа документа в контуре: анализ извлечения
    // собственный `document_type` не несёт (`AnalysisStoreRepository::firstOrCreate()` его не
    // пишет). Боевой путь без этой записи недостижим — статус `screened` документу ставит только
    // `ScreeningOutcomeHandler`, — и без неё операторский re-run (P2.3) не знает, чей job
    // перезапускать.
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::Estimate->value,
    ]);

    (new EstimateExtractionJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Extracted)
        ->and(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1);

    $operator = User::factory()->create();
    $correction = Correction::factory()->create([
        'analysis_id' => $analysis->id,
        // Прогон извлечения этого теста не проходил скрининг — $analysis->document_type пуст;
        // тот же fallback, что ExtractionOrchestrator/GoldExampleRecorder уже применяют (D14).
        'document_type' => $analysis->document_type ?? AiDocumentType::Estimate->value,
        'reason' => CorrectionReason::WrongValue,
        'content' => 'Количество в строке 1 указано неверно',
        'hint' => 'Перепроверяй количество строки 1 по спецификации',
        'is_reusable' => false,
        'created_by' => $operator->id,
    ]);

    app(AnalysisStoreRepository::class)->update($analysis, [
        'review_status' => ReviewStatus::Rejected,
        'reviewed_by' => $operator->id,
        'reviewed_at' => now(),
        'operator_comment' => $correction->content,
    ]);

    // Второй прогон — очередь заготовленных исходов продолжается на том же FakeLlmProvider.
    $fake->andThen(feedbackPassport('1', 1));
    queueOneEstimateRun($fake);

    // `RerunAnalysisAction::run()` диспатчит `EstimateExtractionJob::dispatch(...)->afterCommit()` —
    // под `RefreshDatabase` (тестовая обёртывающая транзакция) Laravel исполняет `afterCommit()`
    // НЕМЕДЛЕННО (`Illuminate\Foundation\Testing\DatabaseTransactionsManager::addCallback()` —
    // «когда есть только обёртывающая транзакция теста, коллбэк выполняется сразу»), поэтому
    // `Queue::fake()` обязателен, чтобы проверить промежуточное состояние (документ откатился к
    // `screened`, ПЕРЕД повторным диспатчем) до того, как второй прогон реально стартует —
    // тот же приём, что `ExtractionOrchestratorTest` («checkpoint self-requeue»).
    Queue::fake();

    $newAnalysis = app(RerunAnalysisAction::class)->run($analysis);

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === $document->id);

    expect($newAnalysis->parent_analysis_id)->toBe($analysis->id)
        ->and($newAnalysis->status)->toBe(AnalysisStatus::Pending)
        ->and($analysis->refresh()->superseded_at)->not->toBeNull()
        ->and($document->refresh()->status)->toBe(DocumentStatus::Screened);

    // Партиция «активный анализ на (версия, шаг)» не разъезжается: старый — история, новый — активен.
    expect(Analysis::query()->active()->where('document_version_id', $analysis->document_version_id)->where('step', AnalysisStep::Extraction)->count())->toBe(1);

    // `Queue::fake()` перехватывает только `dispatch()`; прямой вызов `handle()` (тот же приём,
    // что везде в `ExtractionOrchestratorTest`) реально прогоняет job — второй набор
    // заготовленных исходов FakeLlmProvider потребляется здесь.
    (new EstimateExtractionJob($document->id))->handle();

    expect($newAnalysis->refresh()->status)->toBe(AnalysisStatus::Succeeded)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Extracted)
        ->and(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(0)
        ->and(Estimate::query()->where('analysis_id', $newAnalysis->id)->count())->toBe(1)
        ->and($correction->refresh()->applied_at)->not->toBeNull();

    $secondRunPrompts = array_slice($fake->receivedPrompts(), 5);

    expect($secondRunPrompts)->toHaveCount(5);

    $hintFound = collect($secondRunPrompts)->contains(
        fn ($prompt) => collect($prompt->userParts)->contains(
            fn (string $part): bool => str_contains($part, 'Перепроверяй количество строки 1 по спецификации'),
        ),
    );

    expect($hintFound)->toBeTrue();

    // Системная часть НЕ несёт коррекцию (кэшируемый префикс неизменен, Implementation Rules).
    foreach ($secondRunPrompts as $prompt) {
        expect($prompt->systemPart)->not->toContain('Перепроверяй количество строки 1 по спецификации');
    }

    // Первый (не-re-run) прогон не имел коррекций — userParts состоят из одной части (без hint).
    $firstRunPrompts = array_slice($fake->receivedPrompts(), 0, 5);

    foreach ($firstRunPrompts as $prompt) {
        expect($prompt->userParts)->toHaveCount(1);
    }
});

/**
 * Пара «версия документа + активный анализ извлечения», на которой оператор жмёт «перезапустить»:
 * ось типа несёт вердикт скрининга, как в боевом контуре.
 */
function seedRerunnableExtraction(?string $documentType = null): Analysis
{
    $document = Document::factory()->create(['status' => DocumentStatus::Extracted]);
    $version = seedFeedbackVersion($document);

    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => $documentType ?? AiDocumentType::Estimate->value,
    ]);

    return Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
    ]);
}

test('re-run стадии извлечения диспатчит job через реестр — ядро имени job’а не знает', function (): void {
    Storage::fake('s3');
    Queue::fake();

    $analysis = seedRerunnableExtraction();

    app(RerunAnalysisAction::class)->run($analysis);

    Queue::assertPushed(EstimateExtractionJob::class);
});

test('re-run извлечения при типе без анализатора не мутирует анализ и падает громко', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.analyzers' => []]);

    $analysis = seedRerunnableExtraction();

    // Гейт фальсифицируемости item'а: при ЖЁСТКОМ `EstimateExtractionJob::dispatch()` (состояние
    // до P2.3) перезапуск прошёл бы молча — реестр здесь единственный источник «чей это job».
    expect(fn () => app(RerunAnalysisAction::class)->run($analysis))->toThrow(RuntimeException::class);

    expect($analysis->refresh()->superseded_at)->toBeNull();

    Queue::assertNotPushed(EstimateExtractionJob::class);
});

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole, тот же приём,
// что PromptPolicyTest/AdminPanelAccessTest).
test('оператор авторизован на действия карточки анализа (update-право), аналитик — только на чтение', function (): void {
    artisan('guard:sync-roles');

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);

    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);

    $analysis = Analysis::factory()->create();

    expect($operator->can('admin.analysis.view_any'))->toBeTrue()
        ->and($operator->can('update', $analysis))->toBeTrue()
        ->and($analyst->can('admin.analysis.view_any'))->toBeTrue()
        ->and($analyst->can('update', $analysis))->toBeFalse();
});
