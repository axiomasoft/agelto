<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Llm\FinishReason;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Jobs\Procurement\ShadowAnalysisJob;
use App\Models\Llm\LlmCall;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\LlmCallRecorder;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use App\Services\Llm\ShadowDispatch;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

function runShadowJob(ShadowAnalysisJob $job): void
{
    $job->handle(
        app(LlmRouteResolver::class),
        app(AnalysisStoreRepository::class),
        app(LlmCallRecorder::class),
        app(DocumentAnalyzerRegistry::class),
        app(UsageSnapshotRecorder::class),
    );
}

function seedShadowRoute(string $documentType, LlmPurpose $purpose): LlmRoute
{
    $provider = Provider::factory()->create(['driver' => 'openai_compatible', 'base_url' => 'http://vllm.internal:8000/v1']);

    return LlmRoute::factory()->create([
        'document_type' => $documentType,
        'purpose' => $purpose->value,
        'provider_id' => $provider->id,
        'model' => 'Qwen3-4B-Instruct',
        'priority' => 0,
        'shadow' => true,
    ]);
}

test('afterCall() диспатчит ShadowAnalysisJob на очередь ai_shadow, когда есть активный теневой маршрут', function (): void {
    Queue::fake();
    seedShadowRoute(AiDocumentType::Estimate->value, EstimateLlmPurpose::ExtractRows);

    $analysis = Analysis::factory()->create(['step' => AnalysisStep::Extraction, 'document_type' => 'estimate']);
    $prompt = new LlmPrompt(systemPart: 'система', userParts: ['строки 1-40']);
    $schema = new JsonSchema(['type' => 'object']);

    app(ShadowDispatch::class)->afterCall($analysis, EstimateLlmPurpose::ExtractRows, AiDocumentType::Estimate, $prompt, $schema);

    Queue::assertPushedOn('ai_shadow', ShadowAnalysisJob::class, function (ShadowAnalysisJob $job) use ($analysis): bool {
        return $job->primaryAnalysisId === $analysis->id
            && $job->purpose === EstimateLlmPurpose::ExtractRows->value
            && $job->documentType === AiDocumentType::Estimate
            && $job->systemPart === 'система';
    });
});

test('afterCall() ничего не диспатчит, если для (document_type, purpose) нет теневого маршрута', function (): void {
    Queue::fake();

    $analysis = Analysis::factory()->create();

    app(ShadowDispatch::class)->afterCall($analysis, CoreLlmPurpose::Screening, DocumentTypeScope::Any, new LlmPrompt(systemPart: 's'), null);

    Queue::assertNothingPushed();
});

test('afterCall() пропускает вызовы с fileRefs — Files API не дублируется', function (): void {
    Queue::fake();
    seedShadowRoute(DocumentTypeScope::Any->value, CoreLlmPurpose::Screening);

    $analysis = Analysis::factory()->create();
    $prompt = new LlmPrompt(
        systemPart: 's',
        fileRefs: [new LlmFileHandle(uri: 'files/x', expiresAt: CarbonImmutable::now()->addDay())],
    );

    app(ShadowDispatch::class)->afterCall($analysis, CoreLlmPurpose::Screening, DocumentTypeScope::Any, $prompt, null);

    Queue::assertNothingPushed();
});

test('ShadowAnalysisJob создаёт is_shadow-анализ, пишет LlmCall(purpose=shadow) и считает сравнение с основным', function (): void {
    Storage::fake('s3'); // LlmCallRecorder пишет сырой request/response на config('crawler.raw_disk')='s3'.
    $route = seedShadowRoute(AiDocumentType::Estimate->value, EstimateLlmPurpose::ExtractRows);

    $primary = Analysis::factory()->create([
        'step' => AnalysisStep::Extraction,
        'document_type' => 'estimate',
        'status' => AnalysisStatus::Succeeded,
        'result' => ['estimates' => [['rows' => [['row_index' => 1, 'row_type' => 'line_item', 'name' => 'Кирпич', 'quantity' => '10', 'total_price' => '100.00']]]]],
    ]);

    $shadowOutput = ['estimates' => [['rows' => [['row_index' => 1, 'row_type' => 'line_item', 'name' => 'Кирпич', 'quantity' => '10', 'total_price' => '100.00']]]]];

    $fake = FakeLlmProvider::succeeding(new LlmStructuredResponse(
        data: $shadowOutput,
        usage: new LlmUsage(promptTokens: 50, completionTokens: 10, cachedTokens: 0, totalTokens: 60),
        finishReason: FinishReason::Stop,
        raw: json_encode($shadowOutput, JSON_THROW_ON_ERROR),
    ));
    app()->instance(OpenAiCompatibleProvider::class, $fake);

    $job = new ShadowAnalysisJob(
        primaryAnalysisId: $primary->id,
        purpose: EstimateLlmPurpose::ExtractRows->value,
        documentType: AiDocumentType::Estimate,
        systemPart: 'система',
        userParts: ['строки 1-40'],
        schema: ['type' => 'object'],
    );

    runShadowJob($job);

    $shadow = Analysis::query()->where('is_shadow', true)->where('document_version_id', $primary->document_version_id)->sole();

    expect($shadow->status)->toBe(AnalysisStatus::Succeeded)
        ->and($shadow->provider)->toBe($route->provider->code)
        ->and((float) $shadow->result['comparison']['overall']['f1'])->toBe(1.0);

    $call = LlmCall::query()->where('analysis_id', $shadow->id)->sole();
    expect($call->purpose)->toBe(CoreLlmPurpose::Shadow->value)
        ->and($call->success)->toBeTrue();
});

test('ShadowAnalysisJob на кросс-документном вызове (DocumentTypeScope::Any) не считает сравнение', function (): void {
    Storage::fake('s3');
    seedShadowRoute(DocumentTypeScope::Any->value, CoreLlmPurpose::Screening);

    $primary = Analysis::factory()->create([
        'step' => AnalysisStep::Screening,
        'result' => ['estimates' => [['rows' => [['row_index' => 1, 'row_type' => 'line_item', 'name' => 'Кирпич', 'quantity' => '10', 'total_price' => '100.00']]]]],
    ]);

    $shadowOutput = ['estimates' => [['rows' => [['row_index' => 1, 'row_type' => 'line_item', 'name' => 'Кирпич', 'quantity' => '10', 'total_price' => '100.00']]]]];

    $fake = FakeLlmProvider::succeeding(new LlmStructuredResponse(
        data: $shadowOutput,
        usage: new LlmUsage(promptTokens: 50, completionTokens: 10, cachedTokens: 0, totalTokens: 60),
        finishReason: FinishReason::Stop,
        raw: json_encode($shadowOutput, JSON_THROW_ON_ERROR),
    ));
    app()->instance(OpenAiCompatibleProvider::class, $fake);

    $job = new ShadowAnalysisJob(
        primaryAnalysisId: $primary->id,
        purpose: CoreLlmPurpose::Screening->value,
        documentType: DocumentTypeScope::Any,
        systemPart: 'система',
        userParts: ['текст документа'],
        schema: ['type' => 'object'],
    );

    runShadowJob($job);

    $shadow = Analysis::query()->where('is_shadow', true)->where('document_version_id', $primary->document_version_id)->sole();

    expect($shadow->status)->toBe(AnalysisStatus::Succeeded)
        ->and($shadow->result['comparison'])->toBeNull();
});

test('ShadowAnalysisJob глотает исключение провайдера — боевой путь не затронут, ошибка только в shadow-анализе', function (): void {
    Storage::fake('s3');
    seedShadowRoute(DocumentTypeScope::Any->value, CoreLlmPurpose::Screening);

    $primary = Analysis::factory()->create(['step' => AnalysisStep::Screening]);

    $fake = FakeLlmProvider::throwing(new RuntimeException('vLLM недоступен'));
    app()->instance(OpenAiCompatibleProvider::class, $fake);

    $job = new ShadowAnalysisJob(
        primaryAnalysisId: $primary->id,
        purpose: CoreLlmPurpose::Screening->value,
        documentType: DocumentTypeScope::Any,
        systemPart: 'система',
        userParts: [],
        schema: null,
    );

    // handle() не должен пробрасывать исключение — Implementation Rules P9.4.
    runShadowJob($job);

    $shadow = Analysis::query()->where('is_shadow', true)->sole();
    expect($shadow->status)->toBe(AnalysisStatus::Failed)
        ->and($shadow->last_error)->toContain('vLLM недоступен');
});
