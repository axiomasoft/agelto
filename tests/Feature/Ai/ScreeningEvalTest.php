<?php

declare(strict_types=1);

use App\Enums\Procurement\FinishReason;
use App\Enums\Procurement\LlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

/**
 * P5.6 — ветка `ai:eval:run --task=screening` прогоняет `ScreeningProcessor` (боевой маршрут через
 * `AiRouteResolver`) по `prc_ai_dataset_examples(task=screening)` и сравнивает `document_type`
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
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => LlmPurpose::Screening,
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

function screeningEvalResult(string $documentType, ?string $ignoreReason = null): LlmStructuredResponse
{
    // `tags` НЕ передаётся: Laravel `required` отклоняет ПРИСУТСТВУЮЩИЙ пустой массив (тот же
    // квирк, что обошли в `ScreeningGoldBuilder`, P5.4) — отсутствующий ключ берёт дефолт `[]`.
    return screeningEvalStructured([
        'document_type' => $documentType,
        'confidence' => 0.95,
        'description' => 'фикстура ScreeningEvalTest',
        'page_count_estimate' => 1,
        'ignore_reason' => $ignoreReason,
    ]);
}

test('негатив, правильно классифицированный → accuracy 1.0 и FP 0', function () {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(screeningEvalResult('unknown', 'чертёж без сметного содержания'));
    seedScreeningEvalRoute($fake);

    $document = Document::factory()->create(['procurement_id' => null]);
    $documentVersion = seedScreeningEvalDocumentVersion($document);

    DatasetExample::factory()->create([
        'document_version_id' => $documentVersion->id,
        'task' => 'screening',
        'gold' => ['document_type' => 'unknown', 'confidence' => 1.0, 'description' => null, 'ignore_reason' => 'негатив'],
    ]);

    $this->artisan('ai:eval:run', ['--task' => 'screening'])->assertExitCode(0);

    $run = EvalRun::query()->firstOrFail();

    expect((float) $run->metrics['accuracy'])->toBe(1.0)
        ->and((float) $run->metrics['false_positive_rate'])->toBe(0.0)
        ->and(EvalResult::query()->count())->toBe(1)
        ->and(EvalResult::query()->firstOrFail()->metric)->toBe('screening_accuracy');
});

test('негатив, помеченный estimate → FP 1.0 и запись в перечне расхождений с кодом эталона', function () {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(screeningEvalResult('estimate'));
    seedScreeningEvalRoute($fake);

    $document = Document::factory()->create(['procurement_id' => null]);
    $documentVersion = seedScreeningEvalDocumentVersion($document);

    $fixture = Fixture::factory()->neg()->create(['code' => 'NEG-PDF-EVAL-001']);

    DatasetExample::factory()->create([
        'document_version_id' => $documentVersion->id,
        'fixture_id' => $fixture->id,
        'task' => 'screening',
        'gold' => ['document_type' => 'unknown', 'confidence' => 1.0, 'description' => null, 'ignore_reason' => 'негатив'],
    ]);

    $this->artisan('ai:eval:run', ['--task' => 'screening'])->assertExitCode(0);

    $run = EvalRun::query()->firstOrFail();

    expect((float) $run->metrics['false_positive_rate'])->toBe(1.0)
        ->and((float) $run->metrics['accuracy'])->toBe(0.0);

    $detail = EvalResult::query()->firstOrFail()->details;
    expect($detail['code'])->toBe('NEG-PDF-EVAL-001');
});

test('смета, помеченная contract_draft → FN учтён', function () {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(screeningEvalResult('contract_draft'));
    seedScreeningEvalRoute($fake);

    $document = Document::factory()->create(['procurement_id' => null]);
    $documentVersion = seedScreeningEvalDocumentVersion($document);

    DatasetExample::factory()->create([
        'document_version_id' => $documentVersion->id,
        'task' => 'screening',
        'gold' => ['document_type' => 'estimate', 'confidence' => 1.0, 'description' => null, 'ignore_reason' => null],
    ]);

    $this->artisan('ai:eval:run', ['--task' => 'screening'])->assertExitCode(0);

    $run = EvalRun::query()->firstOrFail();

    expect((float) $run->metrics['false_negative_rate'])->toBe(1.0);
});

test('пустой датасет task=screening → заглушка EvalRun без вызовов', function () {
    $this->artisan('ai:eval:run', ['--task' => 'screening'])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run')
        ->and(EvalResult::query()->count())->toBe(0);
});

test('--dry-run → ноль вызовов', function () {
    DatasetExample::factory()->create(['task' => 'screening']);

    $this->artisan('ai:eval:run', ['--task' => 'screening', '--dry-run' => true])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run')
        ->and(EvalResult::query()->count())->toBe(0)
        ->and(Analysis::query()->count())->toBe(0);
});

test('extraction-ветка осталась зелёной (существующие кейсы EvalRun не сломаны)', function () {
    $this->artisan('ai:eval:run', ['--task' => 'extraction'])->assertExitCode(0);

    expect(EvalRun::query()->count())->toBe(1)
        ->and(EvalRun::query()->firstOrFail()->provider)->toBe('dry-run');
});
