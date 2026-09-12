<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\RevalidateDocumentJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\DocumentRevalidationSelector;
use Illuminate\Support\Facades\Http;

test('ревалидация ждёт завершения текущего анализа даже между checkpoint jobs', function (): void {
    Http::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Screened]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Running,
        'locked_until' => null,
    ]);

    (new RevalidateDocumentJob(documentId: $document->id))->handle();

    Http::assertNothingSent();
    expect($document->refresh()->status)->toBe(DocumentStatus::Screened)
        ->and($document->locked_until)->toBeNull();
});

test('AI job не входит в обработку пока документ занят ревалидацией', function (DocumentStatus $status, AnalysisStep $step, string $jobClass): void {
    Http::preventStrayRequests();
    $document = Document::factory()->create(['status' => $status]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    expect(app(DocumentStoreRepository::class)->acquireRevalidationLease(
        document: $document, owner: RevalidateDocumentJob::class, ttlSeconds: 420,
    ))->toBeTrue();

    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => $step,
        'status' => AnalysisStatus::Pending,
    ]);
    (new $jobClass(documentId: $document->id))->handle();

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Pending)
        ->and($analysis->locked_until)->toBeNull()
        ->and($document->refresh()->locked_by)->toStartWith(RevalidateDocumentJob::class.':');
})->with([
    'screening' => [DocumentStatus::Prepared, AnalysisStep::Screening, ScreeningJob::class],
    'extraction' => [DocumentStatus::Screened, AnalysisStep::Extraction, EstimateExtractionJob::class],
]);

test('selector фильтрует активный анализ до limit и не голодает на первых 25 документах', function (): void {
    $procurement = Procurement::factory()->create(['current_revision_detected_at' => now()]);
    $dueAt = now()->subDays(2);

    for ($index = 0; $index < 13; $index++) {
        $document = Document::factory()->create(['procurement_id' => $procurement->id]);
        DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'downloaded_at' => $dueAt,
        ]);
        $latestVersion = DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'downloaded_at' => $dueAt,
        ]);
        AnalysisRun::factory()->create(['document_version_id' => $latestVersion->id]);
    }

    for ($index = 0; $index < 12; $index++) {
        $document = Document::factory()->create(['procurement_id' => $procurement->id]);
        $analyzedVersion = DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'downloaded_at' => $dueAt->copy()->subDay(),
        ]);
        $analysisRun = AnalysisRun::factory()->succeeded()->create([
            'document_version_id' => $analyzedVersion->id,
        ]);
        Analysis::factory()->create([
            'document_version_id' => $analyzedVersion->id,
            'analysis_run_id' => $analysisRun->id,
            'step' => AnalysisStep::Extraction,
            'status' => AnalysisStatus::Running,
            'locked_until' => now()->addHour(),
        ]);
        DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'downloaded_at' => $dueAt,
        ]);
    }

    $eligible = Document::factory()->create(['procurement_id' => $procurement->id]);
    $olderTiedVersion = DocumentVersion::factory()->create([
        'document_id' => $eligible->id,
        'downloaded_at' => now()->subDay(),
    ]);
    AnalysisRun::factory()->create(['document_version_id' => $olderTiedVersion->id]);
    DocumentVersion::factory()->create([
        'document_id' => $eligible->id,
        'downloaded_at' => $olderTiedVersion->downloaded_at,
    ]);

    $due = app(DocumentRevalidationSelector::class)->due(limit: 25);

    expect($due->modelKeys())->toBe([$eligible->id])
        ->and(app(DocumentStoreRepository::class)->acquireRevalidationLease(
            document: $eligible,
            owner: RevalidateDocumentJob::class,
            ttlSeconds: 420,
        ))->toBeTrue();
});
