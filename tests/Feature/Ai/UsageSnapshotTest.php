<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Llm\LlmCall;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * P4.1 — единый сейм слепка расхода (`UsageSnapshotRecorder`): агрегация по `analysis_id`/шагу
 * (D39), пересчёт вместо инкремента (Implementation Rule 5), слепок прогона = сумма слепков его
 * анализов (§3.6 root/document-contour.md), покрытие обоих контуров (D91) одним путём.
 */
function usageDocumentFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function usageSeedVersion(Document $document, string $fixture = 'sample-multi-page.pdf', string $extension = 'pdf', string $mime = 'application/pdf'): DocumentVersion
{
    $content = usageDocumentFixture($fixture);
    $hash = hash('sha256', $content.$document->id);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => $fixture,
        'extension' => $extension,
        'mime' => $mime,
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);

    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    return $version;
}

function usageSeedScreeningRoute(FakeLlmProvider $fake): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash-lite',
        'priority' => 0,
    ]);
}

function usageScreeningResponse(int $promptTokens, int $completionTokens, FinishReason $finishReason = FinishReason::Stop): LlmStructuredResponse
{
    $data = [
        'document_type' => 'estimate',
        'confidence' => 0.92,
        'description' => 'ЛСР на капитальный ремонт кровли',
        'tags' => ['Ремонт'],
        'page_count_estimate' => 12,
        'ignore_reason' => null,
    ];

    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: $promptTokens, completionTokens: $completionTokens, cachedTokens: 0, totalTokens: $promptTokens + $completionTokens),
        finishReason: $finishReason,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

test('слепок анализа = сумма вызовов её шага, вызов тюнинга (prompt_critic) не входит (D39)', function (): void {
    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Screening]);

    LlmCall::factory()->create([
        'analysis_id' => $analysis->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 500,
        'completion_tokens' => 40,
        'cached_tokens' => 10,
        'total_tokens' => 540,
        'cost_micros' => 1_000,
    ]);
    LlmCall::factory()->create([
        'analysis_id' => $analysis->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 100,
        'completion_tokens' => 20,
        'cached_tokens' => 0,
        'total_tokens' => 120,
        'cost_micros' => 300,
    ]);
    // Вызов критика промтов на ЭТОТ ЖЕ analysis_id — тратился на промт, не на документ (D39).
    LlmCall::factory()->create([
        'analysis_id' => $analysis->id,
        'purpose' => CoreLlmPurpose::PromptCritic->value,
        'prompt_tokens' => 9_999,
        'completion_tokens' => 9_999,
        'cached_tokens' => 0,
        'total_tokens' => 19_998,
        'cost_micros' => 999_999,
    ]);

    app(UsageSnapshotRecorder::class)->finalizeAnalysis($analysis, [
        'status' => AnalysisStatus::Succeeded,
        'finished_at' => now(),
    ]);

    $analysis->refresh();

    expect($analysis->prompt_tokens)->toBe(600)
        ->and($analysis->completion_tokens)->toBe(60)
        ->and($analysis->cached_tokens)->toBe(10)
        ->and($analysis->total_tokens)->toBe(660)
        ->and($analysis->cost_micros)->toBe(1_300)
        ->and($analysis->finished_at)->not->toBeNull();
});

test('повторный вызов сейма не удваивает цифры — пересчёт, а не инкремент', function (): void {
    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Screening]);

    LlmCall::factory()->create([
        'analysis_id' => $analysis->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 500,
        'completion_tokens' => 40,
        'cached_tokens' => 0,
        'total_tokens' => 540,
        'cost_micros' => 1_000,
    ]);

    $recorder = app(UsageSnapshotRecorder::class);
    $recorder->finalizeAnalysis($analysis, ['status' => AnalysisStatus::Succeeded, 'finished_at' => now()]);
    $recorder->finalizeAnalysis($analysis, ['status' => AnalysisStatus::Succeeded, 'finished_at' => now()]);

    expect($analysis->refresh()->cost_micros)->toBe(1_000);
});

test('теневой анализ (is_shadow) считает ТОЛЬКО purpose=shadow — даже при наличии вызова скрининга на том же analysis_id', function (): void {
    $shadow = Analysis::factory()->create(['step' => AnalysisStep::Screening, 'is_shadow' => true]);

    LlmCall::factory()->create([
        'analysis_id' => $shadow->id,
        'purpose' => CoreLlmPurpose::Shadow->value,
        'prompt_tokens' => 200,
        'completion_tokens' => 20,
        'cached_tokens' => 0,
        'total_tokens' => 220,
        'cost_micros' => 500,
    ]);
    LlmCall::factory()->create([
        'analysis_id' => $shadow->id,
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 9_999,
        'completion_tokens' => 9_999,
        'cached_tokens' => 0,
        'total_tokens' => 19_998,
        'cost_micros' => 999_999,
    ]);

    app(UsageSnapshotRecorder::class)->finalizeAnalysis($shadow, ['status' => AnalysisStatus::Succeeded, 'finished_at' => now()]);

    expect($shadow->refresh()->cost_micros)->toBe(500)
        ->and($shadow->prompt_tokens)->toBe(200);
});

test('слепок прогона = сумма слепков его анализов, включая теневой', function (): void {
    $version = DocumentVersion::factory()->create();
    $run = AnalysisRun::factory()->create(['document_version_id' => $version->id]);

    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $run->id,
        'step' => AnalysisStep::Screening,
        'prompt_tokens' => 100, 'completion_tokens' => 10, 'cached_tokens' => 0, 'total_tokens' => 110, 'cost_micros' => 1_000,
    ]);
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $run->id,
        'step' => AnalysisStep::Extraction,
        'prompt_tokens' => 500, 'completion_tokens' => 50, 'cached_tokens' => 20, 'total_tokens' => 570, 'cost_micros' => 5_000,
    ]);
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $run->id,
        'step' => AnalysisStep::Screening,
        'is_shadow' => true,
        'prompt_tokens' => 30, 'completion_tokens' => 3, 'cached_tokens' => 0, 'total_tokens' => 33, 'cost_micros' => 200,
    ]);

    app(UsageSnapshotRecorder::class)->markRunSucceeded($run);

    $run->refresh();

    expect($run->prompt_tokens)->toBe(630)
        ->and($run->completion_tokens)->toBe(63)
        ->and($run->cached_tokens)->toBe(20)
        ->and($run->total_tokens)->toBe(713)
        ->and($run->cost_micros)->toBe(6_200)
        ->and($run->finished_at)->not->toBeNull();
});

test('успешный скрининг — слепок расхода пишется на Analysis И на AnalysisRun (интеграционный путь ScreeningJob)', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::succeeding(usageScreeningResponse(900, 40));
    usageSeedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = usageSeedVersion($document);

    (new ScreeningJob($document->id))->handle();

    $analysis = Analysis::query()->where('document_version_id', $version->id)->where('step', AnalysisStep::Screening)->firstOrFail();
    $run = $analysis->analysisRun;

    // ai.extraction.enabled по умолчанию выключен (см. ScreeningProcessorTest) — извлечение не
    // диспатчится, ScreeningOutcomeHandler::markScreened() терминирует прогон напрямую.
    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->prompt_tokens)->toBe(900)
        ->and($analysis->completion_tokens)->toBe(40)
        ->and($analysis->total_tokens)->toBe(940)
        ->and($analysis->cost_micros)->not->toBeNull()
        ->and($run->refresh()->status->value)->toBe('succeeded')
        ->and($run->prompt_tokens)->toBe(900)
        ->and($run->completion_tokens)->toBe(40)
        ->and($run->cost_micros)->toBe($analysis->cost_micros);
});

test('обучающий документ (площадка «Обучалка») — слепок пишется тем же путём, что боевой (D91)', function (): void {
    Storage::fake('s3');
    Platform::factory()->training()->create();
    $fake = FakeLlmProvider::succeeding(usageScreeningResponse(300, 15));
    usageSeedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared, 'platform_id' => Platform::training()->id]);
    $version = usageSeedVersion($document);

    (new ScreeningJob($document->id))->handle();

    $analysis = Analysis::query()->where('document_version_id', $version->id)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->prompt_tokens)->toBe(300)
        ->and($analysis->cost_micros)->not->toBeNull();
});

test('фатальный finishReason — попытки исчерпаны, Analysis Failed несёт finished_at и ненулевой слепок', function (): void {
    Storage::fake('s3');
    config(['ai.retry.max_attempts' => 1]);
    $fake = FakeLlmProvider::succeeding(usageScreeningResponse(70, 5, FinishReason::Safety));
    usageSeedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = usageSeedVersion($document);

    expect(fn () => (new ScreeningJob($document->id))->handle())->toThrow(RuntimeException::class);

    $analysis = Analysis::query()->where('document_version_id', $version->id)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Failed)
        ->and($analysis->finished_at)->not->toBeNull()
        ->and($analysis->prompt_tokens)->toBe(70)
        ->and($analysis->completion_tokens)->toBe(5);
});

function usageSeedExtractionRoute(FakeLlmProvider $fake): void
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
function usageExtractionStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 500, completionTokens: 50, cachedTokens: 0, totalTokens: 550),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function usageExtractionPassport(?string $estimateNumber, int $rowsExpected): LlmStructuredResponse
{
    return usageExtractionStructured([
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
function usageExtractionRows(array $rowIndexes): LlmStructuredResponse
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

    return usageExtractionStructured(['rows' => $rows, 'incomplete_items' => []]);
}

function usageExtractionTotals(): LlmStructuredResponse
{
    return usageExtractionStructured(['totals' => ['footer' => [
        ['label' => 'Итого', 'value' => '100.00', 'kind' => null, 'children' => []],
    ]]]);
}

test('успешное извлечение — Analysis несёт provider/model (сегодня их писал только скрининг) и слепок = сумма вызовов extract_*', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(usageExtractionPassport('1', 1))
        ->andThen(usageExtractionRows([1]))
        ->andThen(usageExtractionRows([]))
        ->andThen(usageExtractionTotals())
        ->andThen(usageExtractionPassport(null, 0));

    usageSeedExtractionRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    $version = usageSeedVersion($document);

    (new EstimateExtractionJob($document->id))->handle();

    $analysis = Analysis::query()->where('document_version_id', $version->id)->where('step', AnalysisStep::Extraction)->firstOrFail();

    // passport('1',1) → rows([1]) → rows([]) → totals → passport(null,0)-терминатор — 5 вызовов
    // extract_* по 500/50 токенов каждый (фикстура usageExtractionStructured).
    $calls = LlmCall::query()->where('analysis_id', $analysis->id)->count();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->provider)->not->toBeNull()
        ->and($analysis->model)->toBe('gemini-2.5-flash')
        ->and($calls)->toBe(5)
        ->and($analysis->prompt_tokens)->toBe(500 * $calls)
        ->and($analysis->completion_tokens)->toBe(50 * $calls)
        ->and($analysis->cost_micros)->not->toBeNull();
});
