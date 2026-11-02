<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningDecision;
use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\StageCode;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessCore\DocumentFeederFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Historical\AnalysisRecoveryHarness;
use Tests\Support\ProcessCore\Http\PermitFixtures;

test('document retry принадлежит durable due и не имеет отдельной retry команды', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    FinalizeFixtures::authority([StageCode::Screening]);
    $work = DocumentFeederFixtures::analysis('manual');
    $lease = FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, new StageDeferred(DeferralReason::ProviderUnavailable, new RetryAt(new DateTimeImmutable('+1 hour'))));
    expect(app(WorkFeeder::class)->tick('analysis'))->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('deferred')
        ->and(is_file(app_path('Console/Commands/Procurement/PipelineRetryFailed.php')))->toBeFalse();
});

test('ретраит анализ на стадии screening по next_retry_at', function () {
    Queue::fake();
    config(['ai.screening.enabled' => true]);
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Running,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(50))->toBe(1);

    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job) => $job->documentId === $version->document_id);
    Queue::assertPushed(ScreeningJob::class, 1);
    expect($analysis->refresh()->next_retry_at->isFuture())->toBeTrue();
});

// Д-4 (P7.7, research/10 §3): раньше ветка PipelineStage::Extraction бросала LogicException
// безусловно («стадия ещё не реализована (P6)») — провалившийся extraction никогда не
// передиспатчился, хотя EstimateExtractionJob существует с e06434c.
test('ретраит анализ на стадии extraction по next_retry_at — диспатчит EstimateExtractionJob через реестр (Д-4)', function () {
    Queue::fake();
    config(['ai.extraction.enabled' => true]);
    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    // Собственный document_type у анализа извлечения в боевом контуре пуст (см.
    // AnalysisStoreRepository::firstOrCreate()) — ось несёт активный анализ скрининга той же
    // версии документа (тот же fixture-приём, что OperatorFeedbackLoopTest::seedRerunnableExtraction()).
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
        'document_type' => AiDocumentType::Estimate->value,
        'result' => (new StoredScreeningResult(
            confidence: 0.95,
            summary: 'Тестовый результат recovery',
            estimateCardinality: EstimateCardinality::Single,
            tags: [],
            classificationNote: null,
            pageCountEstimate: null,
            decision: new ScreeningDecision(
                disposition: ScreeningDisposition::Extract,
                reason: ScreeningDecisionReason::AnalyzerAvailable,
                extractionDispatched: true,
            ),
        ))->toArray(),
    ]);
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Running,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);
    expect(app(AnalysisRecoveryHarness::class)->reconcile(50))->toBe(1);

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job) => $job->documentId === $document->id);
    Queue::assertPushed(EstimateExtractionJob::class, 1);
    expect($analysis->refresh()->next_retry_at->isFuture())->toBeTrue();
});
