<?php

declare(strict_types=1);

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\LlmPurpose;
use App\Enums\Procurement\PromptStep;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Estimate\Estimate;
use App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Doubles\ExtractionlessAnalyzer;

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
            PromptStep::ExtractionGlobal,
            PromptStep::ExtractionDiscovery,
            PromptStep::ExtractionPassport,
            PromptStep::ExtractionRows,
            PromptStep::ExtractionTotals,
        ])
        ->and($analyzer->purposes())->toBe([
            LlmPurpose::Discovery,
            LlmPurpose::ExtractPassport,
            LlmPurpose::ExtractRows,
            LlmPurpose::ExtractTotals,
        ]);
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
