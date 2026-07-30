<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Estimate\Estimate;
use App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Doubles\ExtractionlessAnalyzer;
use Tests\Support\Doubles\GlobalStepAnalyzer;

/*
 * Реестр «тип документа → анализатор» (P2.3, §5 контракта document-analyzer-contract): одна
 * строка конфига как единственный носитель регистрации, инварианты сборки и поведение на типе
 * без анализатора.
 */

function registry(array $analyzers): DocumentAnalyzerRegistry
{
    config(['ai.analyzers' => $analyzers]);
    app()->forgetInstance(DocumentAnalyzerRegistry::class);

    return app(DocumentAnalyzerRegistry::class);
}

test('одна строка конфига регистрирует анализатор типа', function (): void {
    $found = registry([AiDocumentType::Estimate->value => EstimateAnalyzer::class])->for(AiDocumentType::Estimate);

    expect($found)->toBeInstanceOf(EstimateAnalyzer::class);
});

test('тип без анализатора отдаёт null — реестр решений не принимает', function (): void {
    $found = registry([AiDocumentType::Estimate->value => EstimateAnalyzer::class])->for(AiDocumentType::Protocol);

    expect($found)->toBeNull();
});

test('unknown анализатора не имеет — сборка реестра падает на такой строке конфига', function (): void {
    expect(fn () => registry([AiDocumentType::Unknown->value => EstimateAnalyzer::class]))
        ->toThrow(InvalidArgumentException::class);
});

test('ключ вне AiDocumentType роняет сборку реестра', function (): void {
    expect(fn () => registry(['накладная' => EstimateAnalyzer::class]))
        ->toThrow(InvalidArgumentException::class);
});

test('класс, не реализующий контракт анализатора, роняет сборку реестра', function (): void {
    expect(fn () => registry([AiDocumentType::Estimate->value => Analysis::class]))
        ->toThrow(InvalidArgumentException::class);
});

test('анализатор без извлечения регистрируется и объявляет отсутствие стадии', function (): void {
    $found = registry([AiDocumentType::TechSpec->value => ExtractionlessAnalyzer::class])->for(AiDocumentType::TechSpec);

    expect($found)->toBeInstanceOf(ExtractionlessAnalyzer::class)
        ->and($found?->hasExtraction())->toBeFalse();
});

test('анализатор сметы диспатчит СВОЙ job извлечения — имя job’а ядру не известно', function (): void {
    Queue::fake();

    app(EstimateAnalyzer::class)->dispatchExtraction(42);

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === 42);
});

test('анализатор сметы объявляет только СВОИ шаги и назначения', function (): void {
    $analyzer = app(EstimateAnalyzer::class);

    expect($analyzer->hasExtraction())->toBeTrue()
        ->and($analyzer->promptSteps())->toBe([
            EstimatePromptStep::ExtractionGlobal,
            EstimatePromptStep::ExtractionDiscovery,
            EstimatePromptStep::ExtractionPassport,
            EstimatePromptStep::ExtractionRows,
            EstimatePromptStep::ExtractionTotals,
        ])
        ->and($analyzer->purposes())->toBe([
            EstimateLlmPurpose::Discovery,
            EstimateLlmPurpose::ExtractPassport,
            EstimateLlmPurpose::ExtractRows,
            EstimateLlmPurpose::ExtractTotals,
        ]);
});

test('шаг чужого типа отвергается на записи — promptStepFor() бросает вне множества типа', function (): void {
    $registry = registry([AiDocumentType::Estimate->value => EstimateAnalyzer::class]);

    expect(fn () => $registry->promptStepFor(AiDocumentType::Estimate, 'screening:classify'))
        ->not->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->promptStepFor(DocumentTypeScope::Any, EstimatePromptStep::ExtractionRows->value))
        ->toThrow(InvalidArgumentException::class);
});

test('анализатор с ядерным значением шага валит сборку реестра (§5.4 п.2)', function (): void {
    expect(fn () => registry([AiDocumentType::TechSpec->value => GlobalStepAnalyzer::class]))
        ->toThrow(InvalidArgumentException::class);
});

test('сводка результата отдаёт данные, а не разметку, и не теряет копейки', function (): void {
    $analysis = Analysis::factory()->create([
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
    ]);

    Estimate::factory()->create([
        'analysis_id' => $analysis->id,
        'document_version_id' => $analysis->document_version_id,
        'ordinal' => 1,
        'total_amount' => '0.01',
        'currency' => 'RUB',
        'rows_extracted' => 2,
    ]);

    Estimate::factory()->create([
        'analysis_id' => $analysis->id,
        'document_version_id' => $analysis->document_version_id,
        'ordinal' => 2,
        'total_amount' => '0.02',
        'currency' => 'RUB',
        'rows_extracted' => 3,
    ]);

    $summary = app(EstimateAnalyzer::class)->summarize($analysis);

    expect($summary->title)->toBe('2 сметы, 5 позиций')
        ->and($summary->detailRoute)->toBeNull()
        ->and(array_map(fn ($metric): array => [$metric->label, $metric->value, $metric->unit], $summary->metrics))
        ->toBe([
            ['Сметы', 2, null],
            ['Позиции', 5, null],
            ['Сумма', '0.03', 'RUB'],
        ]);
});
