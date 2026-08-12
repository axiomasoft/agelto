<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

function screeningMatrixResponse(string $type, float $confidence, string $cardinality): LlmStructuredResponse
{
    $data = [
        'document_type' => $type,
        'confidence' => $confidence,
        'summary' => "Матрица P7: {$type}/{$cardinality}",
        'estimate_cardinality' => $cardinality,
        'tags' => ['matrix'],
        'classification_note' => $type === AiDocumentType::Unknown->value ? 'Недостаточно признаков' : null,
    ];

    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 10, completionTokens: 10, cachedTokens: 0, totalTokens: 20),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function seedScreeningMatrix(Document $document, LlmStructuredResponse $response): void
{
    Storage::fake('s3');
    app()->instance(PrismGeminiProvider::class, FakeLlmProvider::succeeding($response));

    $provider = Provider::factory()->create(['driver' => 'gemini']);
    LlmRoute::factory()->create([
        'document_type' => 'any',
        'purpose' => CoreLlmPurpose::Screening->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash-lite',
        'priority' => 0,
    ]);

    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample-multi-page.pdf'));
    $path = 'documents/'.hash('sha256', $content);
    Storage::disk('s3')->put($path, $content);
    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'filename' => 'matrix.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $path,
        'downloaded_at' => now(),
    ]);

    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);
}

test('P7 matrix persists every valid taxonomy/cardinality vector into one v2 backend-owned envelope', function (
    string $type,
    string $cardinality,
    float $confidence,
    string $disposition,
    string $reason,
    DocumentStatus $status,
): void {
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    seedScreeningMatrix($document, screeningMatrixResponse($type, $confidence, $cardinality));

    (new ScreeningJob($document->id))->handle();

    $analysis = Analysis::query()->where('step', AnalysisStep::Screening)->sole();

    expect($analysis->document_type)->toBe($type)
        ->and($analysis->result['schema_version'])->toBe(2)
        ->and($analysis->result['screening'])->toMatchArray([
            'summary' => "Матрица P7: {$type}/{$cardinality}",
            'estimate_cardinality' => $cardinality,
            'tags' => ['matrix'],
        ])
        ->and($analysis->result['decision'])->toMatchArray([
            'disposition' => $disposition,
            'reason' => $reason,
        ])
        ->and($document->refresh()->status)->toBe($status);
})->with([
    'estimate single' => [AiDocumentType::Estimate->value, EstimateCardinality::Single->value, 0.95, 'extract', 'analyzer_available', DocumentStatus::Screened],
    'estimate multiple' => [AiDocumentType::Estimate->value, EstimateCardinality::Multiple->value, 0.95, 'extract', 'analyzer_available', DocumentStatus::Screened],
    'estimate unknown count' => [AiDocumentType::Estimate->value, EstimateCardinality::Unknown->value, 0.95, 'extract', 'analyzer_available', DocumentStatus::Screened],
    'tech spec' => [AiDocumentType::TechSpec->value, EstimateCardinality::None->value, 0.95, 'retain', 'known_type_no_analyzer', DocumentStatus::Screened],
    'contract draft' => [AiDocumentType::ContractDraft->value, EstimateCardinality::None->value, 0.95, 'retain', 'known_type_no_analyzer', DocumentStatus::Screened],
    'protocol' => [AiDocumentType::Protocol->value, EstimateCardinality::None->value, 0.95, 'retain', 'known_type_no_analyzer', DocumentStatus::Screened],
    'unknown with no estimate' => [AiDocumentType::Unknown->value, EstimateCardinality::None->value, 0.95, 'ignore', 'unknown_type', DocumentStatus::Ignored],
    'unknown with unknown count' => [AiDocumentType::Unknown->value, EstimateCardinality::Unknown->value, 0.95, 'ignore', 'unknown_type', DocumentStatus::Ignored],
    'low confidence takes precedence' => [AiDocumentType::Estimate->value, EstimateCardinality::Single->value, 0.39, 'ignore', 'low_confidence', DocumentStatus::Ignored],
    'borderline estimate awaits review' => [AiDocumentType::Estimate->value, EstimateCardinality::Single->value, 0.50, 'review', 'borderline_confidence', DocumentStatus::Screened],
]);
