<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Screening\ScreeningOutcomeHandler;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\ExtractionlessAnalyzer;
use Tests\Support\Doubles\FakeLlmProvider;

/*
 * Сценарии ТЗ 03 §6 для ветвления скрининга (`ScreeningOutcomeHandler`, D36): «не-смета сохраняет
 * тип без разбора», «unknown отсекается», «borderline помечается/эскалируется, не теряется»,
 * «архив со сметой внутри доходит до кандидата», «повтор идемпотентен».
 */

function branchingFixtureContent(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/{$name}"));
}

function seedBranchingVersion(Document $document, string $fixture, string $extension, string $mime): DocumentVersion
{
    $content = branchingFixtureContent($fixture);
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

    // Launch-точка боевого конвейера (§3.5 root/document-contour.md), см. ScreeningProcessorTest.
    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);

    return $version;
}

function seedBranchingRoute(FakeLlmProvider $fake, int $priority = 0, string $model = 'gemini-2.5-flash-lite'): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id,
        'model' => $model,
        'priority' => $priority,
    ]);
}

function branchingResponse(string $documentType, float $confidence): LlmStructuredResponse
{
    $data = [
        'document_type' => $documentType,
        'confidence' => $confidence,
        'summary' => 'тестовый документ',
        'estimate_cardinality' => $documentType === 'estimate' ? 'single' : 'none',
        'tags' => ['тест'],
        'classification_note' => null,
    ];

    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 900, completionTokens: 40, cachedTokens: 0, totalTokens: 940),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

test('не-смета (протокол) сохраняет тип без разбора — screened, extraction не диспатчится', function (): void {
    Storage::fake('s3');
    Queue::fake();
    $fake = FakeLlmProvider::succeeding(branchingResponse('protocol', 0.9));
    seedBranchingRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->document_type)->toBe('protocol');

    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('тип с зарегистрированным анализатором БЕЗ извлечения — screened без диспатча', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config([
        'ai.extraction.enabled' => true,
        'ai.analyzers' => [AiDocumentType::TechSpec->value => ExtractionlessAnalyzer::class],
    ]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('tech_spec', 0.9));
    seedBranchingRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    // Ветка «анализатор есть, извлечения нет» ≠ ветка «анализатора нет»: у первой реестр вернул
    // объект, и молчаливый `dispatchExtraction()` на нём был бы `LogicException`.
    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('outcome события выводится из ТИПА документа, а не из литерала', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.extraction.enabled' => true]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.95));
    seedBranchingRoute($fake);

    $procurement = Procurement::factory()->create();
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared, 'procurement_id' => $procurement->id]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    $event = ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::DocumentScreened)
        ->firstOrFail();

    // Значение прежнее (`estimate`) — иначе поехало бы содержимое `prc_procurement_events`,
    // — но получено из `AiDocumentType::Estimate->value`, а не из строки в ядре.
    expect($event->new_value)->toBe(AiDocumentType::Estimate->value);
});

test('тип БЕЗ анализатора — screened без диспатча, outcome не несёт типа', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.extraction.enabled' => true, 'ai.analyzers' => []]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.95));
    seedBranchingRoute($fake);

    $procurement = Procurement::factory()->create();
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared, 'procurement_id' => $procurement->id]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    $event = ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::DocumentScreened)
        ->firstOrFail();

    expect($event->new_value)->toBe('retain');

    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('unknown отсекается — ignored', function (): void {
    Storage::fake('s3');
    Queue::fake();
    $fake = FakeLlmProvider::succeeding(branchingResponse('unknown', 0.9));
    seedBranchingRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Ignored);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->result['decision']['reason'])->toBe('unknown_type');
});

test('ignore-правило по подстроке в названии закупки отсекает документ независимо от классификации', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.screening.ignore_rules' => ['Поставка продуктов питания']]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.95));
    seedBranchingRoute($fake);

    $procurement = Procurement::factory()->create(['title' => 'Поставка продуктов питания для школьной столовой']);
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared, 'procurement_id' => $procurement->id]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Ignored);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->result['decision']['reason'])->toBe('ignore_rule');

    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('borderline confidence — screened с typed review decision, extraction не диспатчится даже для estimate', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.extraction.enabled' => true]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.5));
    seedBranchingRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->result['decision'])->toMatchArray([
        'disposition' => 'review',
        'reason' => 'borderline_confidence',
        'extraction_dispatched' => false,
    ]);

    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('borderline confidence с borderline_action=escalate — один повторный скрининг на маршрут выше, затем обычное ветвление', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.screening.borderline_action' => 'escalate', 'ai.extraction.enabled' => true]);

    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.5))
        ->andThen(branchingResponse('estimate', 0.9));
    seedBranchingRoute($fake, priority: 0, model: 'gemini-2.5-flash-lite');
    seedBranchingRoute($fake, priority: 1, model: 'gemini-2.5-pro');

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened);

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->model)->toBe('gemini-2.5-pro')
        ->and($analysis->result['screening']['confidence'])->toBe(0.9)
        ->and($analysis->result['decision']['reason'])->toBe('analyzer_available');

    // Одна резка/загрузка на попытку — эскалация повторяет весь скрининг (Code Guidance P5.2).
    expect($fake->uploadedFiles())->toHaveCount(2);

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === $document->id);
});

test('архив со сметой внутри доходит до кандидата на извлечение (сквозной сценарий)', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.extraction.enabled' => true]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.95));
    seedBranchingRoute($fake);

    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    seedBranchingVersion($document, 'sample-with-xlsx.zip', 'zip', 'application/octet-stream');

    (new PrepareDocumentJob($document->id))->handle();

    $child = Document::query()
        ->where('procurement_id', $document->procurement_id)
        ->where('id', '!=', $document->id)
        ->firstOrFail();

    (new PrepareDocumentJob($child->id))->handle();
    expect($child->refresh()->status)->toBe(DocumentStatus::Prepared);

    // `ai.screening.enabled` не включён (в отличие от `ai.extraction.enabled` выше) — launch-точка
    // `PrepareDocumentJob:82` не срабатывает, ScreeningJob запускается вручную ниже.
    $childVersion = DocumentVersion::query()->where('document_id', $child->id)->latest('downloaded_at')->firstOrFail();
    app(AnalysisRunStoreRepository::class)->start($childVersion->id, RunMode::Auto);

    (new ScreeningJob($child->id))->handle();

    expect($child->refresh()->status)->toBe(DocumentStatus::Screened);

    $analysis = Analysis::query()->where('document_version_id', $childVersion->id)->where('step', AnalysisStep::Screening)->firstOrFail();
    expect($analysis->document_type)->toBe('estimate');

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === $child->id);
})->skip(fn () => ! extension_loaded('gd'), 'требует gd (Mpdf) — см. docker/php/Dockerfile');

test('теневой screening сохраняет v2 evidence без изменения primary document и без dispatch', function (): void {
    Queue::fake();
    config(['ai.extraction.enabled' => true]);

    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'status' => AnalysisStatus::Running,
        'is_shadow' => true,
    ]);

    app(ScreeningOutcomeHandler::class)->handle(
        document: $document,
        analysis: $analysis,
        result: ScreeningResult::validateAndCreate(branchingResponse('estimate', 0.95)->data),
    );

    expect($document->refresh()->status)->toBe(DocumentStatus::Screened)
        ->and($analysis->refresh()->status)->toBe(AnalysisStatus::Succeeded)
        ->and($analysis->result['schema_version'])->toBe(2)
        ->and($analysis->result['decision']['disposition'])->toBe('extract')
        ->and($analysis->result['decision']['extraction_dispatched'])->toBeFalse();

    Queue::assertNotPushed(EstimateExtractionJob::class);
});

test('повтор идемпотентен — событие document_screened и дочерний dispatch не дублируются', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.extraction.enabled' => true]);
    $fake = FakeLlmProvider::succeeding(branchingResponse('estimate', 0.95))->andThen(branchingResponse('estimate', 0.95));
    seedBranchingRoute($fake);

    $procurement = Procurement::factory()->create();
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared, 'procurement_id' => $procurement->id]);
    seedBranchingVersion($document, 'sample-multi-page.pdf', 'pdf', 'application/pdf');

    (new ScreeningJob($document->id))->handle();
    (new ScreeningJob($document->id))->handle();

    expect(Analysis::query()->where('step', AnalysisStep::Screening)->count())->toBe(1)
        ->and($fake->uploadedFiles())->toHaveCount(1);

    expect(ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::DocumentScreened)
        ->count())->toBe(1);

    Queue::assertPushed(EstimateExtractionJob::class, 1);
});
