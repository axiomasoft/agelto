<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

function screeningFixtureContent(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function seedScreeningVersion(Document $document, string $fixture, string $extension, string $mime): DocumentVersion
{
    $content = screeningFixtureContent($fixture);
    $hash = hash('sha256', $content);
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

    // Launch-точка боевого конвейера (§3.5 root/document-contour.md) — здесь эмулируется
    // `PrepareDocumentJob:82`: `ScreeningJob::handle()` резолвит `Analysis` через
    // `AnalysisStoreRepository::firstOrCreate()`, требующий активный прогон (D93).
    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    return $version;
}

function seedScreeningRoute(FakeLlmProvider $fake): void
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

function screeningResultResponse(): LlmStructuredResponse
{
    $data = [
        'document_type' => 'estimate',
        'confidence' => 0.92,
        'description' => 'ЛСР на капитальный ремонт кровли',
        'tags' => ['Ремонт', 'ЛСР'],
        'page_count_estimate' => 12,
        'ignore_reason' => null,
    ];

    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 900, completionTokens: 40, cachedTokens: 0, totalTokens: 940),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

test('успешный скрининг PDF — Analysis succeeded, document_type/result заполнены, Document доводится до screened (P5.2 branching)', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::succeeding(screeningResultResponse());
    seedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();

    expect($analysis->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->document_type)->toBe('estimate')
        ->and($analysis->result['confidence'])->toBe(0.92)
        ->and($analysis->result['tags'])->toBe(['Ремонт', 'ЛСР'])
        ->and($analysis->provider)->not->toBeNull()
        ->and($analysis->model)->toBe('gemini-2.5-flash-lite')
        ->and($analysis->rules_hash)->not->toBeNull()
        ->and($analysis->finished_at)->not->toBeNull();

    // document_type=estimate, confidence=0.92 (> ai.screening.confidence_high) — обычное
    // ветвление ScreeningOutcomeHandler (P5.2, D36): Document доводится до screened; dispatch
    // EstimateExtractionJob — за отдельным гейтом config('ai.extraction.enabled'), выключенным
    // по умолчанию (не тестируется здесь, см. ScreeningBranchingTest).
    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    $uploaded = $fake->uploadedFiles();
    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]['mimeType'])->toBe('application/pdf');
});

test('скрининг режет только первые N страниц (сгенерированный срез короче исходных 10 страниц)', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::succeeding(screeningResultResponse());
    seedScreeningRoute($fake);
    config(['ai.screening.pages' => 4]);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    // Срез удаляется в finally ДО возврата из run() (Code Guidance P5.1) — по факту его больше
    // нет на диске к моменту ассерта, проверяем по имени временного файла (`PdfPageSlicer`
    // именует срез `screening-slice-*`, не переиспользует имя скачанного оригинала
    // `screening_*.pdf`) — реальная резка страниц регресс-тестируется юнит-тестом `PdfPageSlicer`.
    $uploaded = $fake->uploadedFiles();
    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]['path'])->toContain('screening-slice-')
        ->and(file_exists($uploaded[0]['path']))->toBeFalse();
});

test('MAX_TOKENS — ответ не гидратируется, попытка фиксируется как retryable (job-level re-ask через backoff)', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::truncated();
    seedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    expect(fn () => (new ScreeningJob($document->id))->handle())->toThrow(RuntimeException::class);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();

    expect($analysis->attempts)->toBe(1)
        ->and($analysis->failed_permanently)->toBeFalse()
        ->and($analysis->next_retry_at)->not->toBeNull()
        ->and($analysis->last_error)->toContain('MAX_TOKENS')
        ->and($analysis->status)->toBe(AnalysisStatus::Running);
});

test('фатальный finishReason исчерпывает попытки сразу — Analysis переходит в failed', function (): void {
    Storage::fake('s3');
    config(['ai.retry.max_attempts' => 1]);

    $fatal = new LlmStructuredResponse(
        data: [],
        usage: new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0),
        finishReason: FinishReason::Safety,
        raw: '',
    );
    $fake = FakeLlmProvider::succeeding($fatal);
    seedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    expect(fn () => (new ScreeningJob($document->id))->handle())->toThrow(RuntimeException::class);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();

    expect($analysis->attempts)->toBe(1)
        ->and($analysis->failed_permanently)->toBeTrue()
        ->and($analysis->status)->toBe(AnalysisStatus::Failed);
});

test('изображение (скан) идёт целиком без резки — vision-путь', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::succeeding(screeningResultResponse());
    seedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample.png', 'png', 'image/png');

    (new ScreeningJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->status)->toBe(AnalysisStatus::Succeeded);

    $uploaded = $fake->uploadedFiles();
    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]['mimeType'])->toBe('image/png');
});

test('неподдержанный формат, дошедший до скрининга, — unprocessable(unreadable_format), не generic failed', function (): void {
    Storage::fake('s3');

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample.xlsx', 'docx', 'application/msword');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('unreadable_format');

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->status)->toBe(AnalysisStatus::Skipped)
        ->and($analysis->result['unprocessable_reason'])->toBe('unreadable_format');
});

test('битый PDF — qpdf проваливается, unprocessable(unreadable_format) вместо retryable failed', function (): void {
    Storage::fake('s3');

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => 'documents/broken.pdf',
        'downloaded_at' => now(),
    ]);
    Storage::disk('s3')->put($version->storage_path, 'не является PDF-файлом');
    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->last_error)->toContain('unreadable_format');

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->status)->toBe(AnalysisStatus::Skipped);
});

test('повторный запуск идемпотентен — статус документа prepared не даёт создать дубль Analysis', function (): void {
    Storage::fake('s3');
    $fake = FakeLlmProvider::succeeding(screeningResultResponse())->andThen(screeningResultResponse());
    seedScreeningRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();
    (new ScreeningJob($document->id))->handle();

    expect(Analysis::query()->where('step', AnalysisStep::Screening)->count())->toBe(1)
        ->and($fake->uploadedFiles())->toHaveCount(1);
});
