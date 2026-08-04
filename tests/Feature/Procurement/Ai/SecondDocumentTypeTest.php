<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
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
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\SupportsResultComparison;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Analyzer\TechSpecFakeAnalyzer;
use Tests\Support\Analyzer\TechSpecFakeExtractionJob;
use Tests\Support\Doubles\FakeLlmProvider;

/*
 * Доказательство расширяемости D14/D26 (P2.6): второй тип документа — существующий
 * `AiDocumentType::TechSpec`, зарегистрированный ОДНОЙ строкой конфига ТОЛЬКО в этом файле —
 * проходит скрининг → диспатчится СВОЙ job анализатора, а не сметный; сводка результата уходит
 * через реестр, без обращения к сметным таблицам.
 */

function registerTechSpecAnalyzer(): void
{
    config(['ai.analyzers' => [AiDocumentType::TechSpec->value => TechSpecFakeAnalyzer::class]]);
    app()->forgetInstance(DocumentAnalyzerRegistry::class);
}

function secondTypeFixtureContent(): string
{
    return file_get_contents(base_path('tests/Fixtures/Documents/sample-multi-page.pdf'));
}

function seedSecondTypeVersion(Document $document): DocumentVersion
{
    $content = secondTypeFixtureContent();
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => 'sample-multi-page.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);

    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    return $version;
}

function seedSecondTypeRoute(FakeLlmProvider $fake): void
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

function techSpecScreeningResponse(): LlmStructuredResponse
{
    $data = [
        'document_type' => AiDocumentType::TechSpec->value,
        'confidence' => 0.95,
        'description' => 'фиктивный второй тип (P2.6)',
        'tags' => ['тест'],
        'page_count_estimate' => 3,
        'ignore_reason' => null,
    ];

    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 900, completionTokens: 40, cachedTokens: 0, totalTokens: 940),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

test('документ второго типа проходит скрининг → диспатчится job ЕГО анализатора, а не сметный', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.extraction.enabled' => true]);
    registerTechSpecAnalyzer();

    $fake = FakeLlmProvider::succeeding(techSpecScreeningResponse());
    seedSecondTypeRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedSecondTypeVersion($document);

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->document_type)->toBe(AiDocumentType::TechSpec->value);

    Queue::assertPushed(
        TechSpecFakeExtractionJob::class,
        fn (TechSpecFakeExtractionJob $job): bool => $job->documentId === $document->id,
    );
    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('сводка результата второго типа получается через реестр, без обращения к сметным таблицам', function (): void {
    registerTechSpecAnalyzer();

    $analysis = Analysis::factory()->create([
        'step' => AnalysisStep::Extraction,
        'document_type' => AiDocumentType::TechSpec->value,
    ]);

    $analyzer = app(DocumentAnalyzerRegistry::class)->for(AiDocumentType::TechSpec);
    $summary = $analyzer?->summarize($analysis);

    expect($analyzer)->toBeInstanceOf(TechSpecFakeAnalyzer::class)
        ->and($summary?->title)->toBe('Фиктивный второй тип')
        ->and($summary?->metrics)->toHaveCount(1)
        ->and($summary?->metrics[0]->label)->toBe('Позиции');
});

test('фиктивный второй тип НЕ реализует SupportsResultComparison — реестр даёт штатный null анализатору без способности', function (): void {
    registerTechSpecAnalyzer();

    $analyzer = app(DocumentAnalyzerRegistry::class)->for(AiDocumentType::TechSpec);

    expect($analyzer)->not->toBeInstanceOf(SupportsResultComparison::class);
});
