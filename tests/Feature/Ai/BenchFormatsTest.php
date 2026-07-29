<?php

declare(strict_types=1);

use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\GoldSource;
use App\Enums\Procurement\LlmPurpose;
use App\Enums\Procurement\RepresentationKind;
use App\Models\Llm\LlmCall;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Bench\FormatBenchRunner;
use App\Services\Procurement\Prepare\RepresentationResolver;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

// Harness бенчмарка форматов (Scope Included P6.1, D48–D50): сухой обход БЕЗ LLM-вызова + сейм
// принуждения представления. `FakeLlmProvider` — единственный допустимый провайдер в тестах
// (реальный вызов — блокирующее замечание, `.claude/rules/agelto-reviewer-overrides.md`).

function benchFixture(string $code = 'EST-XLSX-KS2-001'): Fixture
{
    return Fixture::factory()->create(['code' => $code]);
}

/**
 * @return array{0: Document, 1: DocumentVersion}
 */
function benchDocumentAndVersion(?int $procurementId = null, string $extension = 'xlsx'): array
{
    $document = Document::factory()->create(['procurement_id' => $procurementId]);

    $content = "исходник {$extension}";
    Storage::disk('s3')->put("documents/{$extension}/original.{$extension}", $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => $extension,
        'storage_path' => "documents/{$extension}/original.{$extension}",
        'size_bytes' => strlen($content),
    ]);

    return [$document, $version];
}

function benchDatasetExample(Fixture $fixture, DocumentVersion $version): DatasetExample
{
    // Связь «эталон ↔ рабочая копия» (§2 root/document-contour.md) — в проде её пишет
    // GoldImportCommand::resolveWorkingCopy(), здесь эквивалент для синтетики теста: гвард
    // FormatBenchRunner (Implementation Rule 1 P1.6) требует $fixture->document_id === $document->id.
    $fixture->update(['document_id' => $version->document_id]);

    return DatasetExample::factory()->create([
        'fixture_id' => $fixture->id,
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'source' => 'deterministic',
        'gold' => ['estimates' => []],
    ]);
}

function seedBenchRoute(FakeLlmProvider $fake): void
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
}

/**
 * @param  array<string, mixed>  $data
 */
function benchStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 500, completionTokens: 50, cachedTokens: 0, totalTokens: 550),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * Минимальный цикл `runWholeDocument()` в 3 вызова: паспорт с готовыми итогами (totals_done сразу
 * true) → пустой диапазон строк (rows_done) → паспорт-терминатор (смет больше нет).
 */
function benchMinimalCycle(): array
{
    return [
        benchStructured([
            'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
            'estimate_number' => '1',
            'currency' => 'RUB',
            'base_year' => null,
            'price_level' => null,
            'rows_expected' => 0,
            'totals' => ['footer' => [['label' => 'Итого', 'value' => '1.00', 'kind' => null, 'children' => []]]],
        ]),
        benchStructured(['rows' => [], 'incomplete_items' => []]),
        benchStructured([
            'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
            'estimate_number' => null,
            'currency' => 'RUB',
            'base_year' => null,
            'price_level' => null,
            'rows_expected' => 0,
            'totals' => null,
        ]),
    ];
}

beforeEach(function (): void {
    Storage::fake('s3');
    Storage::fake('estimates');
});

test('сухой прогон не делает ни одного LLM-вызова и заполняет predicted', function (): void {
    $fixture = benchFixture();
    [, $version] = benchDocumentAndVersion();
    benchDatasetExample($fixture, $version);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::TextGrid,
        'storage_path' => 'documents/xlsx/grid.txt',
        'mime' => 'text/plain',
        'size_bytes' => 2000,
        'page_count' => null,
        'token_estimate' => 500,
    ]);
    Storage::disk('s3')->put('documents/xlsx/grid.txt', str_repeat('a', 2000));

    seedBenchRoute(FakeLlmProvider::succeeding(benchStructured(['rows' => [], 'incomplete_items' => []])));

    $before = LlmCall::query()->count();

    $artifact = app(FormatBenchRunner::class)->run([$fixture->code], ['text_grid'], dryRun: true, limit: null);

    expect(LlmCall::query()->count())->toBe($before)
        ->and($artifact['mode'])->toBe('dry')
        ->and($artifact['cells'])->toHaveCount(1);

    $cell = $artifact['cells'][0];
    expect($cell['applicable'])->toBeTrue()
        ->and($cell['variant'])->toBe('text_grid')
        ->and($cell['predicted']['input_tokens'])->toBe(500)
        ->and($cell['predicted']['input_cost_micros'])->toBeGreaterThan(0)
        ->and($cell['actual'])->toBeNull()
        ->and($cell['quality'])->toBeNull();
});

test('ячейка с несуществующим вариантом — not_applicable, прогон не падает', function (): void {
    $fixture = benchFixture();
    [, $version] = benchDocumentAndVersion();
    benchDatasetExample($fixture, $version);

    seedBenchRoute(FakeLlmProvider::succeeding(benchStructured(['rows' => [], 'incomplete_items' => []])));

    $artifact = app(FormatBenchRunner::class)->run([$fixture->code], ['pdf_ocr'], dryRun: true, limit: null);

    expect($artifact['cells'])->toHaveCount(1)
        ->and($artifact['cells'][0]['variant'])->toBe('pdf_ocr')
        ->and($artifact['cells'][0]['applicable'])->toBeFalse()
        ->and($artifact['cells'][0]['representation'])->toBeNull();
});

test('принуждение kind отдаёт именно его — отсутствующий вариант даёт null (not_applicable)', function (): void {
    $version = DocumentVersion::factory()->create(['extension' => 'xlsx']);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::TextGrid,
        'storage_path' => 'documents/forced/grid.txt',
        'mime' => 'text/plain',
    ]);

    $forced = app(RepresentationResolver::class)->forLlm($version, RepresentationKind::TextGrid);
    $missing = app(RepresentationResolver::class)->forLlm($version, RepresentationKind::PdfOcr);

    expect($forced)->not->toBeNull()
        ->and($forced['storagePath'])->toBe('documents/forced/grid.txt')
        ->and($forced['extension'])->toBe('txt')
        ->and($missing)->toBeNull();
});

test('forceKind = null даёт сегодняшнее поведение резолвера байт-в-байт', function (): void {
    $version = DocumentVersion::factory()->create([
        'extension' => 'xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'storage_path' => 'documents/bb/original.xlsx',
    ]);

    $withoutForce = app(RepresentationResolver::class)->forLlm($version);
    $withNullForce = app(RepresentationResolver::class)->forLlm($version, null);

    expect($withNullForce)->toBe($withoutForce)
        ->and($withoutForce)->toBe([
            'storagePath' => 'documents/bb/original.xlsx',
            'disk' => (string) config('crawler.documents_disk'),
            'extension' => 'xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
});

test('вызовы двух ячеек подряд отделены границей по id — usage не смешивается', function (): void {
    $fixture = benchFixture();
    [, $version] = benchDocumentAndVersion();
    benchDatasetExample($fixture, $version);

    $realPdf = file_get_contents(base_path('tests/Fixtures/Documents/sample-multi-page.pdf'));
    Storage::disk('s3')->put('documents/xlsx/pdf.pdf', $realPdf);
    Storage::disk('s3')->put('documents/xlsx/ocr.pdf', $realPdf);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::Pdf,
        'storage_path' => 'documents/xlsx/pdf.pdf',
        'mime' => 'application/pdf',
        'page_count' => 1,
    ]);
    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::PdfOcr,
        'storage_path' => 'documents/xlsx/ocr.pdf',
        'mime' => 'application/pdf',
        'page_count' => 1,
    ]);

    [$passport1, $rows1, $terminator1] = benchMinimalCycle();
    [$passport2, $rows2, $terminator2] = benchMinimalCycle();

    $fake = FakeLlmProvider::succeeding($passport1)->andThen($rows1)->andThen($terminator1)
        ->andThen($passport2)->andThen($rows2)->andThen($terminator2);

    seedBenchRoute($fake);

    $artifact = app(FormatBenchRunner::class)->run([$fixture->code], ['pdf', 'pdf_ocr'], dryRun: false, limit: null);

    $byVariant = collect($artifact['cells'])->keyBy('variant');

    expect($byVariant['pdf']['outcome']['status'])->toBe('succeeded')
        ->and($byVariant['pdf']['actual']['calls'])->toBe(3)
        ->and($byVariant['pdf_ocr']['outcome']['status'])->toBe('succeeded')
        ->and($byVariant['pdf_ocr']['actual']['calls'])->toBe(3);

    // Каждая ячейка видит РОВНО свои 3 вызова, а не накопленные 6 (граница по id, не по analysis_id —
    // обе ячейки делят один Analysis(document_version_id, step), D48).
    expect(LlmCall::query()->count())->toBe(6);
});

test('документ — НЕ рабочая копия этого эталона (fixture->document_id не совпадает) — отказ, прогон не падает', function (): void {
    // Implementation Rule 1 P1.6 (root/document-contour.md §5): гвард переведён со структурного
    // procurement_id на прямую связь с эталоном — отказ теперь по несовпадению
    // $fixture->document_id/$version->document_id, а не по наличию закупки у документа.
    $fixture = benchFixture();
    [, $version] = benchDocumentAndVersion();
    benchDatasetExample($fixture, $version);
    $fixture->update(['document_id' => Document::factory()->create()->id]);

    seedBenchRoute(FakeLlmProvider::succeeding(benchStructured(['rows' => [], 'incomplete_items' => []])));

    $artifact = app(FormatBenchRunner::class)->run([$fixture->code], [], dryRun: true, limit: null);

    expect($artifact['cells'])->toBeEmpty()
        ->and($artifact['rejected'])->toHaveCount(1)
        ->and($artifact['rejected'][0])->toContain($fixture->code);
});

test('эталон без детерминированного gold пропущен — не входит в матрицу', function (): void {
    $fixture = Fixture::factory()->create([
        'code' => 'NEG-XLSX-OTHER-001',
        'type_profile' => [
            'class' => 'est',
            'form' => 'KS2',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::None->value,
        ],
    ]);
    [, $version] = benchDocumentAndVersion();
    benchDatasetExample($fixture, $version);

    seedBenchRoute(FakeLlmProvider::succeeding(benchStructured(['rows' => [], 'incomplete_items' => []])));

    $artifact = app(FormatBenchRunner::class)->run([$fixture->code], [], dryRun: true, limit: null);

    expect($artifact['cells'])->toBeEmpty()
        ->and($artifact['rejected'])->toBeEmpty();
});

test('повторный сухой прогон даёт тот же артефакт (кроме generated_at)', function (): void {
    $fixture = benchFixture();
    [, $version] = benchDocumentAndVersion();
    benchDatasetExample($fixture, $version);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => RepresentationKind::TextGrid,
        'storage_path' => 'documents/xlsx/grid.txt',
        'mime' => 'text/plain',
        'size_bytes' => 2000,
        'page_count' => null,
        'token_estimate' => 500,
    ]);
    Storage::disk('s3')->put('documents/xlsx/grid.txt', str_repeat('a', 2000));

    seedBenchRoute(FakeLlmProvider::succeeding(benchStructured(['rows' => [], 'incomplete_items' => []])));

    $runner = app(FormatBenchRunner::class);
    $first = $runner->run([$fixture->code], ['text_grid'], dryRun: true, limit: null);
    $second = $runner->run([$fixture->code], ['text_grid'], dryRun: true, limit: null);

    unset($first['generated_at'], $second['generated_at']);

    expect($second)->toBe($first);
});
