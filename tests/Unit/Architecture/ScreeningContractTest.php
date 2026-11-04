<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Document\DocumentFormat;
use App\Enums\Procurement\Document\PreparationResult;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Validation\StructuredResponseValidator;
use Tests\Support\Documents\DocumentFormatContract;

test('contract has a vector for every taxonomy and format family', function (): void {
    $formats = DocumentFormatContract::cases();

    expect(array_keys($formats))->toEqualCanonicalizing(array_map(
        static fn (DocumentFormat $format): string => $format->value,
        DocumentFormat::cases(),
    ))
        ->and(collect($formats)->where('result', PreparationResult::ReadyForScreening)->keys()->all())->not->toBeEmpty()
        ->and(collect($formats)->where('result', PreparationResult::ExpandedContainer)->keys()->all())->toEqualCanonicalizing(['zip', '7z', 'rar'])
        ->and(AiDocumentType::values())->toEqualCanonicalizing(['estimate', 'tech_spec', 'contract_draft', 'protocol', 'unknown']);
});

test('rejects cardinality combinations that would lie about visible estimate evidence', function (string $type, string $cardinality): void {
    $raw = json_encode([
        'document_type' => $type,
        'confidence' => 0.9,
        'summary' => 'Некорректная комбинация матрицы',
        'estimate_cardinality' => $cardinality,
        'tags' => [],
        'classification_note' => null,
    ], JSON_THROW_ON_ERROR);

    $outcome = (new StructuredResponseValidator)->validate(new LlmStructuredResponse(
        data: [],
        usage: new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0),
        finishReason: FinishReason::Stop,
        raw: $raw,
    ), ScreeningResult::class);

    expect($outcome->isRetryable())->toBeTrue();
})->with([
    'estimate cannot claim none' => [AiDocumentType::Estimate->value, EstimateCardinality::None->value],
    'known non-estimate cannot claim one' => [AiDocumentType::TechSpec->value, EstimateCardinality::Single->value],
    'known non-estimate cannot claim many' => [AiDocumentType::ContractDraft->value, EstimateCardinality::Multiple->value],
    'known non-estimate cannot claim unknown count' => [AiDocumentType::Protocol->value, EstimateCardinality::Unknown->value],
]);
