<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\LlmPurpose;
use App\Jobs\Procurement\EstimateExtractionJob;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

// Feature-тесты оконного пути (Scope Included P6.4, сценарии (д)/(е) из phases/P6.md — (а)-(г)
// покрыты Unit-тестами SegmentAssembler). Документ 10 страниц (tests/Fixtures/Documents/
// sample-multi-page.pdf), лимит маршрута искусственно занижен (max_pages=5) — preflight выбирает
// Windowed. window_pages=3/window_overlap_pages=1 (config-дефолт) → 5 discovery-окон:
// [1-3],[3-5],[5-7],[7-9],[9-10].

function windowedFixtureContent(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function windowedSeedVersion(Document $document, string $fixture = 'sample-multi-page.pdf'): DocumentVersion
{
    $content = windowedFixtureContent($fixture);
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    return DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => $fixture,
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);
}

function windowedSeedRoutes(FakeLlmProvider $fake): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::ExtractRows,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => LlmPurpose::Discovery,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function windowedStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 200, completionTokens: 20, cachedTokens: 0, totalTokens: 220),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  list<array{page: int, marker: string, title?: ?string}>  $markers
 */
function windowedDiscovery(array $markers): LlmStructuredResponse
{
    return windowedStructured(['markers' => $markers]);
}

function windowedPassport(?string $estimateNumber, int $rowsExpected): LlmStructuredResponse
{
    return windowedStructured([
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
function windowedRows(array $rowIndexes): LlmStructuredResponse
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

    return windowedStructured(['rows' => $rows, 'incomplete_items' => []]);
}

function windowedTotals(): LlmStructuredResponse
{
    return windowedStructured(['totals' => ['footer' => [
        ['label' => 'Итого', 'value' => '100.00', 'kind' => null, 'children' => []],
    ]]]);
}

/**
 * `config('ai.capabilities.<model-с-точками>.<key>')` не работает (dot-notation, тот же приём,
 * что ExtractionOrchestratorTest) — правим массив целиком.
 */
function overrideWindowedCapability(string $model, string $key, mixed $value): void
{
    $capabilities = (array) config('ai.capabilities', []);
    $capabilities[$model][$key] = $value;
    config(['ai.capabilities' => $capabilities]);
}

test('оконный путь: discovery → 1 сегмент → passport → rows-окна → totals — тот же результат-shape, что row-range (е)', function (): void {
    Storage::fake('s3');

    // 5 discovery-окон: [1-3],[3-5],[5-7],[7-9],[9-10] — старт на стр.1, конец на стр.10, остальные
    // окна ничего не находят (документ — одна большая смета через весь файл).
    $fake = FakeLlmProvider::succeeding(windowedDiscovery([['page' => 1, 'marker' => 'estimate_start', 'title' => 'Смета №1']]))
        ->andThen(windowedDiscovery([]))
        ->andThen(windowedDiscovery([]))
        ->andThen(windowedDiscovery([]))
        ->andThen(windowedDiscovery([['page' => 10, 'marker' => 'estimate_end']]))
        ->andThen(windowedPassport('1', 2))
        ->andThen(windowedRows([1, 2]))
        ->andThen(windowedRows([]))
        ->andThen(windowedRows([]))
        ->andThen(windowedRows([]))
        ->andThen(windowedRows([]))
        ->andThen(windowedTotals());

    windowedSeedRoutes($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    windowedSeedVersion($document);

    overrideWindowedCapability('gemini-2.5-flash', 'max_pages', 5);
    config(['ai.extraction.windowed_enabled' => true]);

    (new EstimateExtractionJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->result['estimates'])->toHaveCount(1)
        ->and($analysis->result['estimates'][0]['estimate_number'])->toBe('1')
        ->and($analysis->result['estimates'][0]['rows'])->toHaveCount(2);

    expect($document->refresh()->status)->toBe(DocumentStatus::Extracted);

    // Персист (P6.3, Stitcher/Validator/Persister неизменны) отрабатывает без изменений на
    // оконном результате — та же shape, что row-range.
    expect(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1);

    // Windowed-путь НЕ грузит документ целиком ни разу — только маленькие срезы per-window/segment
    // (12 вызовов = 5 discovery + 1 паспорт + 5 rows-окон + 1 totals).
    expect($fake->uploadedFiles())->toHaveCount(12);
});

test('checkpoint self-requeue ПОСЕРЕДИНЕ discovery — прогресс содержит корректный next_window_index, маркеры не теряются (д)', function (): void {
    Storage::fake('s3');
    Queue::fake();

    $fake = FakeLlmProvider::succeeding(windowedDiscovery([['page' => 1, 'marker' => 'estimate_start', 'title' => 'Смета №1']]))
        ->andThen(windowedDiscovery([]));

    windowedSeedRoutes($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    windowedSeedVersion($document);

    overrideWindowedCapability('gemini-2.5-flash', 'max_pages', 5);
    config(['ai.extraction.windowed_enabled' => true, 'ai.extraction.checkpoint_ranges' => 2]);

    (new EstimateExtractionJob($document->id))->handle();

    Queue::assertPushed(EstimateExtractionJob::class, 1);

    $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Running)
        ->and($analysis->progress['discovery']['done'])->toBeFalse()
        ->and($analysis->progress['discovery']['next_window_index'])->toBe(2)
        ->and($analysis->progress['discovery']['markers'])->toHaveCount(1)
        ->and($analysis->progress['discovery']['markers'][0]['page'])->toBe(1);

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);
});
