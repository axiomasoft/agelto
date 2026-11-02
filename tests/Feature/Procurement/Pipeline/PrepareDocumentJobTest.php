<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\Historical\AnalysisRecoveryHarness;
use Tests\Support\ProcessCore\Historical\PrepareJobHarness;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

beforeEach(fn () => StoredDocumentFixtures::reset());

test('после commit Prepared без screening dispatch reconciler ставит единственный следующий job', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    AnalysisRun::factory()->create(['document_version_id' => $version->id]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(1);
    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job): bool => $job->documentId === $document->id);
    Queue::assertPushed(ScreeningJob::class, 1);
});

test('orphan prepared без run создаёт один initial run и восстанавливает screening', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(1)
        ->and(AnalysisRun::query()->where('document_version_id', $version->id)->count())->toBe(1);

    Queue::assertPushed(ScreeningJob::class, 1);
});

test('terminal run history не запускается повторно recovery-проходом', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    AnalysisRun::factory()->create([
        'document_version_id' => $version->id,
        'finished_at' => now(),
    ]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(0)
        ->and(AnalysisRun::query()->where('document_version_id', $version->id)->count())->toBe(1);

    Queue::assertNotPushed(ScreeningJob::class);
});

test('recovery limit ограничивает входную выборку, а не только число dispatch', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    foreach (range(1, 20) as $number) {
        $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
        DocumentVersion::factory()->create(['document_id' => $document->id]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(1);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);
    Queue::assertPushed(ScreeningJob::class, 1);
});

test('PrepareDocumentJob откатывает Prepared при отказе INSERT analysis run', function (): void {
    Storage::fake('s3');
    Queue::fake();
    config(['ai.screening.enabled' => true]);
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $content = '%PDF-1.4 atomic';
    $path = 'documents/atomic.pdf';
    Storage::disk('s3')->put($path, $content);
    DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'filename' => 'atomic.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $path,
        'disk' => 's3',
    ]);

    expect(fn () => (new PrepareJobHarness(
        documentId: $document->id,
        mode: RunMode::Manual,
        initiatedBy: PHP_INT_MAX,
    ))->handle())->toThrow(QueryException::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and(AnalysisRun::query()->count())->toBe(0);
    Queue::assertNotPushed(ScreeningJob::class);
});

test('два recovery ticks создают один run и один claimed continuation', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    $reconciler = app(AnalysisRecoveryHarness::class);

    expect($reconciler->reconcile(limit: 1))->toBe(1)
        ->and($reconciler->reconcile(limit: 1))->toBe(0)
        ->and(AnalysisRun::query()->where('document_version_id', $version->id)->count())->toBe(1);

    Queue::assertPushed(ScreeningJob::class, 1);
});

test('orphan Prepared ждёт выключенный screening и восстанавливается после включения', function (): void {
    Queue::fake();
    $document = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    $reconciler = app(AnalysisRecoveryHarness::class);

    config(['ai.screening.enabled' => false]);
    expect($reconciler->reconcile(limit: 1))->toBe(0)
        ->and(AnalysisRun::query()->where('document_version_id', $version->id)->exists())->toBeFalse();

    config(['ai.screening.enabled' => true]);
    expect($reconciler->reconcile(limit: 1))->toBe(1)
        ->and(AnalysisRun::query()->where('document_version_id', $version->id)->count())->toBe(1);
});

test('terminal history перед due документом не вызывает starvation', function (): void {
    Queue::fake();
    config(['ai.screening.enabled' => true]);

    foreach (range(1, 20) as $number) {
        $terminalDocument = Document::factory()->create(['status' => DocumentStatus::Prepared]);
        $terminalVersion = DocumentVersion::factory()->create(['document_id' => $terminalDocument->id]);
        AnalysisRun::factory()->create([
            'document_version_id' => $terminalVersion->id,
            'finished_at' => now(),
        ]);
    }

    $due = Document::factory()->create(['status' => DocumentStatus::Prepared]);
    $dueVersion = DocumentVersion::factory()->create(['document_id' => $due->id]);
    AnalysisRun::factory()->create(['document_version_id' => $dueVersion->id]);

    expect(app(AnalysisRecoveryHarness::class)->reconcile(limit: 1))->toBe(1);
    Queue::assertPushed(ScreeningJob::class, fn (ScreeningJob $job): bool => $job->documentId === $due->id);
});
