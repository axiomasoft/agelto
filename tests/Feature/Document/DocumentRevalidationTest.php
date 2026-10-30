<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Jobs\Procurement\RevalidateDocumentJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\DocumentRevalidationSelector;
use App\Services\Procurement\PipelineDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

function revalidationDocument(Procurement $procurement, array $attributes = []): Document
{
    return Document::factory()->create([
        'procurement_id' => $procurement->id,
        'status' => DocumentStatus::Prepared,
        'source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=stable-uid',
        ...$attributes,
    ]);
}

beforeEach(fn () => LegacyBusinessNetwork::install());

test('revision-trigger ограниченно ставит существующий uid на ревалидацию', function () {
    Queue::fake();
    $procurement = Procurement::factory()->create(['current_revision_detected_at' => now()]);
    $due = revalidationDocument(procurement: $procurement);
    DocumentVersion::factory()->create(['document_id' => $due->id, 'downloaded_at' => now()->subDay()]);
    $fresh = revalidationDocument(procurement: $procurement, attributes: ['external_id' => 'fresh-uid']);
    DocumentVersion::factory()->create(['document_id' => $fresh->id, 'downloaded_at' => now()->addMinute()]);

    $dispatched = app(DocumentRevalidationSelector::class)->dispatchDue(limit: 1);

    expect($dispatched)->toBe(1);
    Queue::assertPushed(RevalidateDocumentJob::class, fn (RevalidateDocumentJob $job): bool => $job->documentId === $due->id);
    Queue::assertPushed(RevalidateDocumentJob::class, 1);
});

test('low-rate policy подбирает старую версию без новой редакции', function () {
    Queue::fake();
    config(['crawler.documents_revalidation_after_days' => 30]);
    $procurement = Procurement::factory()->create(['current_revision_detected_at' => now()->subDays(60)]);
    $document = revalidationDocument(procurement: $procurement);
    DocumentVersion::factory()->create(['document_id' => $document->id, 'downloaded_at' => now()->subDays(31)]);

    expect(app(DocumentRevalidationSelector::class)->dispatchDue(limit: 10))->toBe(1);
    Queue::assertPushed(RevalidateDocumentJob::class, fn (RevalidateDocumentJob $job): bool => $job->documentId === $document->id);
});

test('selector уважает removal backoff permanent failure и активный lease', function () {
    Queue::fake();
    $procurement = Procurement::factory()->create(['current_revision_detected_at' => now()]);

    foreach ([
        ['removed_at' => now()],
        ['next_retry_at' => now()->addHour()],
        ['failed_permanently' => true],
        ['locked_by' => 'another-worker', 'locked_until' => now()->addHour()],
    ] as $attributes) {
        $document = revalidationDocument(procurement: $procurement, attributes: $attributes);
        DocumentVersion::factory()->create(['document_id' => $document->id, 'downloaded_at' => now()->subDay()]);
    }

    expect(app(DocumentRevalidationSelector::class)->dispatchDue(limit: 10))->toBe(0);
    Queue::assertNotPushed(RevalidateDocumentJob::class);
});

test('production revalidation прежнего uid с новыми bytes создаёт версию и возвращает документ в подготовку', function () {
    Sleep::fake();
    Storage::fake('s3');
    Queue::fake();
    Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
    $procurement = Procurement::factory()->create(['current_revision_detected_at' => now()]);
    $document = revalidationDocument(procurement: $procurement);
    DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => hash('sha256', (string) file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'))),
        'downloaded_at' => now()->subDay(),
    ]);
    Http::fake(['*filestore*' => Http::response(
        body: (string) file_get_contents(base_path('tests/Fixtures/Documents/sample-v2.pdf')),
        status: 200,
    )]);

    app(DocumentRevalidationSelector::class)->dispatchForProcurement(procurementId: $procurement->id);

    $job = null;
    Queue::assertPushed(RevalidateDocumentJob::class, function (RevalidateDocumentJob $candidate) use (&$job): bool {
        $job = $candidate;

        return true;
    });
    expect($job)->toBeInstanceOf(RevalidateDocumentJob::class);
    $job->handle();

    expect(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(2)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::DocumentReplaced)
            ->count())->toBe(1);

    app(PipelineDispatcher::class)->dispatch(procurement: $procurement);
    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $candidate): bool => $candidate->documentId === $document->id);
});

test('production revalidation тех же bytes сохраняет терминал и сбрасывает прошлые ошибки', function () {
    Sleep::fake();
    Storage::fake('s3');
    Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
    $procurement = Procurement::factory()->create(['current_revision_detected_at' => now()]);
    $document = revalidationDocument(procurement: $procurement, attributes: [
        'status' => DocumentStatus::Unprocessable,
        'unprocessable_reason' => UnprocessableReason::UnreadableFormat,
        'attempts' => 4,
        'last_error' => 'transient download failure',
        'last_error_at' => now()->subMinute(),
        'next_retry_at' => now()->subSecond(),
    ]);
    $content = (string) file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
    DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => hash('sha256', $content),
        'downloaded_at' => now()->subDay(),
    ]);
    Http::fake(['*filestore*' => Http::response(body: $content, status: 200)]);

    (new RevalidateDocumentJob(documentId: $document->id))->handle();

    $document->refresh();

    expect(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1)
        ->and($document->status)->toBe(DocumentStatus::Unprocessable)
        ->and($document->unprocessable_reason)->toBe(UnprocessableReason::UnreadableFormat)
        ->and($document->attempts)->toBe(0)
        ->and($document->last_error)->toBeNull()
        ->and($document->last_error_at)->toBeNull()
        ->and($document->next_retry_at)->toBeNull()
        ->and($document->failed_permanently)->toBeFalse();
});

test('возврат A B A восстанавливает порядок версий событие и новый анализ даже в один момент времени', function (): void {
    $this->freezeTime();
    Sleep::fake();
    Storage::fake('s3');
    Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
    $procurement = Procurement::factory()->create();
    $document = revalidationDocument(procurement: $procurement, attributes: ['status' => DocumentStatus::Extracted]);
    $contentA = (string) file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
    $contentB = (string) file_get_contents(base_path('tests/Fixtures/Documents/sample-v2.pdf'));
    $versionA = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => hash('sha256', $contentA),
        'downloaded_at' => now(),
    ]);
    $oldRun = AnalysisRun::factory()->succeeded()->create(['document_version_id' => $versionA->id]);
    $oldAnalysis = Analysis::factory()->create([
        'document_version_id' => $versionA->id,
        'analysis_run_id' => $oldRun->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
    ]);
    Http::fake(['*filestore*' => Http::sequence()->push(body: $contentB)->push(body: $contentA)->push(body: $contentA)]);

    (new RevalidateDocumentJob(documentId: $document->id))->handle();
    $versions = app(DocumentStoreRepository::class);
    expect($versions->latestVersion(documentId: $document->id)->id)->not->toBe($versionA->id);
    $document->refresh()->update(['status' => DocumentStatus::Extracted]);

    (new RevalidateDocumentJob(documentId: $document->id))->handle();

    expect($versions->latestVersion(documentId: $document->id)->id)->toBe($versionA->id)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and($oldAnalysis->refresh()->superseded_at)->not->toBeNull();

    $document->update(['status' => DocumentStatus::Prepared]);
    (new RevalidateDocumentJob(documentId: $document->id))->handle();
    expect($versions->latestVersion(documentId: $document->id)->id)->toBe($versionA->id)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Prepared)
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(2)
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::DocumentReplaced)->count())->toBe(2);

    app(AnalysisRunStoreRepository::class)->start(
        documentVersionId: $versionA->id, mode: RunMode::Auto,
    );
    $newAnalysis = app(AnalysisStoreRepository::class)->firstOrCreate(
        documentVersionId: $versionA->id, step: AnalysisStep::Screening,
    );
    expect($newAnalysis->id)->not->toBe($oldAnalysis->id)
        ->and($newAnalysis->status)->toBe(AnalysisStatus::Pending);
});
