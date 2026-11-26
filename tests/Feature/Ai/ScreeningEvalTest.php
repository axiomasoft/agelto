<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\CatalogOnlyProvider;
use App\Services\Procurement\Screening\ScreeningEvalMetrics;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * ветка `estimate:eval:run --task=screening` прогоняет `ScreeningProcessor` (боевой маршрут через
 * `LlmRouteResolver`) по `prc_ai_dataset_examples(task=screening)` и сравнивает `document_type`
 * предсказания с `gold.document_type` через `ScreeningEvalMetrics`, независимый от extraction
 * `EvalMetrics` (§8.4, НЕ трогается — Validation-критерий 4). `FakeLlmProvider` — тот же приём,
 * что `EvalRunTest` (extraction): «FakeLlm недопустим» относится к БОЕВОМУ прогону владельца
 * (Implementation Rules), не к тестам самой команды.
 */
function screeningEvalFixturePath(): string
{
    return base_path('tests/Fixtures/Documents/sample-multi-page.pdf');
}

function seedScreeningEvalDocumentVersion(Document $document): DocumentVersion
{
    $content = file_get_contents(screeningEvalFixturePath());
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    return DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => 'screening-fixture.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);
}

function seedScreeningEvalRoute(FakeLlmProvider $fake): void
{
    app()->instance(CatalogOnlyProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function screeningEvalStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 50, completionTokens: 10, cachedTokens: 0, totalTokens: 60),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function screeningEvalResult(string $documentType, ?string $classificationNote = null): LlmStructuredResponse
{
    return screeningEvalStructured([
        'document_type' => $documentType,
        'confidence' => 0.95,
        'summary' => 'фикстура ScreeningEvalTest',
        'estimate_cardinality' => $documentType === 'estimate' ? 'single' : 'none',
        'tags' => [],
        'classification_note' => $classificationNote,
    ]);
}

test('негатив, правильно классифицированный → accuracy 1.0 и FP 0', function (): void {
    $metrics = new ScreeningEvalMetrics;
    $prediction = ScreeningResult::from([
        'document_type' => 'unknown', 'confidence' => 0.95, 'summary' => 'fixture',
        'estimate_cardinality' => 'none', 'tags' => [], 'classification_note' => null]);
    $result = $metrics->aggregate([$metrics->compare(['document_type' => 'unknown'], $prediction)]);
    expect($result['accuracy'])->toBe(1.0)
        ->and($result['false_positive_rate'])->toBe(0.0)
        ->and($result['false_negative_rate'])->toBe(null);
});

test('негатив, помеченный estimate → FP 1.0 и запись в перечне расхождений с кодом эталона', function (): void {
    $metrics = new ScreeningEvalMetrics;
    $prediction = ScreeningResult::from([
        'document_type' => 'estimate', 'confidence' => 0.95, 'summary' => 'fixture',
        'estimate_cardinality' => 'single', 'tags' => [], 'classification_note' => null]);
    $result = $metrics->aggregate([$metrics->compare(['document_type' => 'unknown'], $prediction)]);
    expect($result['accuracy'])->toBe(0.0)
        ->and($result['false_positive_rate'])->toBe(1.0)
        ->and($result['false_negative_rate'])->toBe(null);
});

test('смета, помеченная contract_draft → FN учтён', function (): void {
    $metrics = new ScreeningEvalMetrics;
    $prediction = ScreeningResult::from([
        'document_type' => 'contract_draft', 'confidence' => 0.95, 'summary' => 'fixture',
        'estimate_cardinality' => 'none', 'tags' => [], 'classification_note' => null]);
    $result = $metrics->aggregate([$metrics->compare(['document_type' => 'estimate'], $prediction)]);
    expect($result['accuracy'])->toBe(0.0)
        ->and($result['false_positive_rate'])->toBe(null)
        ->and($result['false_negative_rate'])->toBe(1.0);
});

test('пустой датасет task=screening → заглушка EvalRun без вызовов', function () {
    $this->artisan('estimate:eval:run', ['--task' => 'screening'])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run')
        ->and(EvalResult::query()->count())->toBe(0);
});

test('--dry-run → ноль вызовов', function () {
    DatasetExample::factory()->create(['task' => 'screening']);

    $this->artisan('estimate:eval:run', ['--task' => 'screening', '--dry-run' => true])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run')
        ->and(EvalResult::query()->count())->toBe(0)
        ->and(Analysis::query()->count())->toBe(0);
});

test('extraction-ветка осталась зелёной (существующие кейсы EvalRun не сломаны)', function () {
    $this->artisan('estimate:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run');
});
