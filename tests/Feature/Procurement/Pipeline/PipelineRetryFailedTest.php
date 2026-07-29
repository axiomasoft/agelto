<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
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

test('ретраит анализ на стадии screening по next_retry_at', function () {
    Queue::fake();
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Failed,
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'failed_permanently' => false,
    ]);
    // Прогон исходного (провалившегося) анализа в реальном пайплайне терминируется тем же
    // вызовом AnalysisStoreRepository::update(), что ставит status=Failed (D93, §3.6
    // root/document-contour.md) — фабрика пишет статус напрямую, минуя репозиторий, поэтому
    // здесь это явно: иначе launch-точка ретрая (PipelineRetryFailed:131) не сможет завести
    // новый прогон — partial unique отклонит его при ещё «открытом» прогоне фабрики.
    app(AnalysisRunStoreRepository::class)->markFailed($analysis->analysisRun);

    $this->artisan('pipeline:retry-failed')->assertSuccessful();

    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job) => $job->documentId === $version->document_id);
    Queue::assertPushed(ScreeningJob::class, 1);
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
