<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningDecision;
use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Historical\AnalysisRecoveryHarness;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

/** @return array{DocumentVersion, AnalysisRun} */
function pipelineRecoveryVersion(Document $document): array
{
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $run = AnalysisRun::factory()->create(['document_version_id' => $version->id]);

    return [$version, $run];
}

/** @return array<string, mixed> */
function pipelineRecoveryScreeningResult(string $disposition, bool $extractionDispatched): array
{
    return (new StoredScreeningResult(
        confidence: 0.95,
        summary: 'Тестовый результат recovery',
        estimateCardinality: EstimateCardinality::Single,
        tags: [],
        classificationNote: null,
        pageCountEstimate: null,
        decision: new ScreeningDecision(
            disposition: ScreeningDisposition::from($disposition),
            reason: ScreeningDecisionReason::AnalyzerAvailable,
            extractionDispatched: $extractionDispatched,
        ),
    ))->toArray();
}

test('восстанавливает обе DB→queue границы ровно одним job при повторном sweep', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true, 'ai.extraction.enabled' => true]);

    $prepared = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    [$preparedVersion] = pipelineRecoveryVersion($prepared);

    $screened = Document::factory()->create(['status' => DocumentStatus::Screened]);
    [$screenedVersion, $screenedRun] = pipelineRecoveryVersion($screened);
    Analysis::factory()->create([
        'document_version_id' => $screenedVersion->id,
        'analysis_run_id' => $screenedRun->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::Estimate->value,
        'result' => pipelineRecoveryScreeningResult(disposition: 'extract', extractionDispatched: true),
    ]);

    $reconciler = app(AnalysisRecoveryHarness::class);

    expect($reconciler->reconcile(limit: 10))->toBe(2)
        ->and($reconciler->reconcile(limit: 10))->toBe(0);
    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job): bool => $job->documentId === $prepared->id);
    Queue::assertPushed(ScreeningJob::class, 1);
    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === $screened->id);
    Queue::assertPushed(EstimateExtractionJob::class, 1);

    expect(Analysis::query()->active()->where('document_version_id', $preparedVersion->id)->where('step', AnalysisStep::Screening)->value('next_retry_at'))
        ->not->toBeNull();
});

test('не продолжает permanent failure и screening disposition без extraction', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true, 'ai.extraction.enabled' => true]);

    $prepared = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    [$preparedVersion, $preparedRun] = pipelineRecoveryVersion($prepared);
    Analysis::factory()->create([
        'document_version_id' => $preparedVersion->id,
        'analysis_run_id' => $preparedRun->id,
        'status' => AnalysisStatus::Failed,
        'attempts' => 5,
        'failed_permanently' => true,
    ]);

    foreach (['retain', 'review', 'ignore'] as $disposition) {
        $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
        [$version, $run] = pipelineRecoveryVersion($document);
        Analysis::factory()->create([
            'document_version_id' => $version->id,
            'analysis_run_id' => $run->id,
            'step' => AnalysisStep::Screening,
            'status' => AnalysisStatus::Succeeded,
            'document_type' => AiDocumentType::Estimate->value,
            'result' => pipelineRecoveryScreeningResult(disposition: $disposition, extractionDispatched: false),
        ]);
    }

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 10))->toBe(0);
    Queue::assertNothingPushed();
});

test('repair доводит stranded succeeded extraction из screened в extracted без нового job', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true, 'ai.extraction.enabled' => true]);
    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    [$version, $run] = pipelineRecoveryVersion($document);
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $run->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'finished_at' => now(),
    ]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 10))->toBe(1)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Extracted);
    Queue::assertNothingPushed();
});

test('recovery доходит до следующего документа после завершённых и отложенных записей', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true, 'ai.extraction.enabled' => true]);

    $terminal = Document::factory()->create(['status' => DocumentStatus::Screened]);
    [$terminalVersion, $terminalRun] = pipelineRecoveryVersion(document: $terminal);
    $terminalRun->update(['finished_at' => now()]);

    $waiting = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    [$waitingVersion, $waitingRun] = pipelineRecoveryVersion(document: $waiting);
    Analysis::factory()->create([
        'document_version_id' => $waitingVersion->id,
        'analysis_run_id' => $waitingRun->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Pending,
        'next_retry_at' => now()->addDay(),
    ]);

    $due = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    pipelineRecoveryVersion(document: $due);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(1);
    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job): bool => $job->documentId === $due->id);
    Queue::assertPushed(ScreeningJob::class, 1);
});

test('выключенный screening не занимает LIMIT перед due extraction', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => false, 'ai.extraction.enabled' => true]);

    $prepared = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    pipelineRecoveryVersion(document: $prepared);

    $screened = Document::factory()->create(['status' => DocumentStatus::Screened]);
    [$version, $run] = pipelineRecoveryVersion(document: $screened);
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $run->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::Estimate->value,
        'result' => pipelineRecoveryScreeningResult(disposition: 'extract', extractionDispatched: true),
    ]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(1);
    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job): bool => $job->documentId === $screened->id);
    Queue::assertNotPushed(ScreeningJob::class);
});

test('два PostgreSQL worker получают ровно один recovery claim', function (): void {
    $fixture = ConcurrentTransactions::execute(<<<'PHP'
$document = \App\Models\Procurement\Document\Document::factory()->create(attributes: [
    'status' => \App\Enums\Procurement\Document\DocumentStatus::Prepared,
    'procurement_id' => null,
]);
$version = \App\Models\Procurement\Document\DocumentVersion::factory()->create(attributes: [
    'document_id' => $document->id,
]);
$run = \App\Models\Procurement\Ai\AnalysisRun::factory()->create(attributes: [
    'document_version_id' => $version->id,
]);
$analysis = \App\Models\Procurement\Ai\Analysis::factory()->create(attributes: [
    'document_version_id' => $version->id,
    'analysis_run_id' => $run->id,
    'step' => \App\Enums\Procurement\Ai\AnalysisStep::Screening,
    'status' => \App\Enums\Procurement\Ai\AnalysisStatus::Running,
    'attempts' => 1,
    'next_retry_at' => now()->subMinute(),
]);

return [
    'analysis_id' => $analysis->id,
    'run_id' => $run->id,
    'version_id' => $version->id,
    'document_id' => $document->id,
    'platform_id' => $document->platform_id,
];
PHP);

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => pipelineRecoveryClaimWorker(analysisId: $fixture['analysis_id']),
            'second' => pipelineRecoveryClaimWorker(analysisId: $fixture['analysis_id']),
        ]);

        expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
            ->and((int) $workers['first']['claimed'] + (int) $workers['second']['claimed'])->toBe(1);
    } finally {
        ConcurrentTransactions::execute(<<<'PHP'
\Tests\Support\ProcessCore\StoredDocumentFixtures::reset();
return ['cleaned' => true];
PHP);

    }
});

function pipelineRecoveryClaimWorker(int $analysisId): string
{
    return <<<PHP
\$analysis = \App\Models\Procurement\Ai\Analysis::query()->findOrFail({$analysisId});

return [
    'claimed' => app(\App\Repositories\Procurement\Ai\AnalysisStoreRepository::class)->claimRecovery(
        analysis: \$analysis,
        holdMinutes: 10,
    ),
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()'),
];
PHP;
}
