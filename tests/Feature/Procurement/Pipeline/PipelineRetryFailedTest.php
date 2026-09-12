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
use App\Jobs\Procurement\DownloadDocumentJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Support\Facades\Queue;

test('ретраит документ, у которого наступил next_retry_at, и не трогает документы без попыток/в ожидании backoff', function () {
    Queue::fake();
    $due = Document::factory()->create([
        'status' => DocumentStatus::Downloaded,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);
    $notYetDue = Document::factory()->create([
        'status' => DocumentStatus::Downloaded,
        'attempts' => 1,
        'next_retry_at' => now()->addHour(),
        'failed_permanently' => false,
    ]);
    $neverFailed = Document::factory()->create([
        'status' => DocumentStatus::Downloaded,
        'attempts' => 0,
        'next_retry_at' => null,
        'failed_permanently' => false,
    ]);
    $deadLetter = Document::factory()->create([
        'status' => DocumentStatus::Downloaded,
        'attempts' => 5,
        'next_retry_at' => null,
        'failed_permanently' => true,
    ]);

    $this->artisan('pipeline:retry-failed')->assertSuccessful();

    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job) => $job->documentId === $due->id);
    Queue::assertPushed(PrepareDocumentJob::class, 1);
    expect($due->refresh()->next_retry_at->isFuture())->toBeTrue();

    Queue::assertNotPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job) => in_array($job->documentId, [
        $notYetDue->id, $neverFailed->id, $deadLetter->id,
    ], true));
});

test('claim атомарный: захваченный документ второй раз в том же тике не диспатчится', function () {
    Queue::fake();
    Document::factory()->create([
        'status' => DocumentStatus::Downloaded,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);

    $this->artisan('pipeline:retry-failed')->assertSuccessful();
    $this->artisan('pipeline:retry-failed')->assertSuccessful();

    Queue::assertPushed(PrepareDocumentJob::class, 1);
});

test('ретраит только наступившее скачивание discovered-документа отдельной job', function () {
    Queue::fake();
    $due = Document::factory()->create([
        'status' => DocumentStatus::Discovered,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);
    $future = Document::factory()->create([
        'status' => DocumentStatus::Discovered,
        'attempts' => 1,
        'next_retry_at' => now()->addHour(),
        'failed_permanently' => false,
    ]);
    $permanent = Document::factory()->create([
        'status' => DocumentStatus::Discovered,
        'attempts' => 5,
        'next_retry_at' => null,
        'failed_permanently' => true,
    ]);

    $this->artisan('pipeline:retry-failed')->assertSuccessful();

    Queue::assertPushed(DownloadDocumentJob::class, fn (DownloadDocumentJob $job) => $job->documentId === $due->id);
    Queue::assertPushed(DownloadDocumentJob::class, 1);
    Queue::assertNotPushed(DownloadDocumentJob::class, fn (DownloadDocumentJob $job) => in_array($job->documentId, [$future->id, $permanent->id], true));
    expect($due->refresh()->next_retry_at->isFuture())->toBeTrue();
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

    $this->artisan('pipeline:retry-failed')->assertSuccessful();

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
    $this->artisan('pipeline:retry-failed')->assertSuccessful();

    Queue::assertPushed(EstimateExtractionJob::class, fn (EstimateExtractionJob $job) => $job->documentId === $document->id);
    Queue::assertPushed(EstimateExtractionJob::class, 1);
    expect($analysis->refresh()->next_retry_at->isFuture())->toBeTrue();
});

test('лимит --limit ограничивает выборку за тик', function () {
    Queue::fake();
    Document::factory()->count(3)->create([
        'status' => DocumentStatus::Downloaded,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);

    $this->artisan('pipeline:retry-failed', ['--limit' => 2])->assertSuccessful();

    Queue::assertPushed(PrepareDocumentJob::class, 2);
});

test('discovered с нулём попыток подбирается тем же bounded claim', function (): void {
    Queue::fake();
    $document = Document::factory()->create([
        'status' => DocumentStatus::Discovered,
        'attempts' => 0,
        'next_retry_at' => null,
        'failed_permanently' => false,
    ]);

    $this->artisan('pipeline:retry-failed', ['--limit' => 10])->assertSuccessful();

    Queue::assertPushed(DownloadDocumentJob::class, fn (DownloadDocumentJob $job): bool => $job->documentId === $document->id);
    $document->refresh();
    expect($document->attempts)->toBe(0)
        ->and($document->next_retry_at)->not->toBeNull();
});

test('discovered с активным lease не получает конкурирующий download claim', function (): void {
    Queue::fake();
    Document::factory()->create([
        'status' => DocumentStatus::Discovered,
        'attempts' => 0,
        'next_retry_at' => null,
        'failed_permanently' => false,
        'locked_by' => 'active-worker:token',
        'locked_until' => now()->addMinutes(5),
    ]);

    $this->artisan('pipeline:retry-failed', ['--limit' => 10])->assertSuccessful();

    Queue::assertNotPushed(DownloadDocumentJob::class);
});
