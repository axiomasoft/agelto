<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\SupportsResultComparison;
use Tests\Support\Analyzer\TechSpecFakeAnalyzer;

function registerTechSpecAnalyzer(): void
{
    config(['ai.analyzers' => [AiDocumentType::TechSpec->value => TechSpecFakeAnalyzer::class]]);
    app()->forgetInstance(DocumentAnalyzerRegistry::class);
}

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
