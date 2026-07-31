<?php

declare(strict_types=1);

use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\DocumentDownloader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

function sampleDocumentContent(string $version = 'v1'): string
{
    return file_get_contents(base_path("tests/Fixtures/Documents/sample-{$version}.pdf"));
}

/**
 * Активный узел скоупа `document_download` (research/02 §9.1) — резолвер не выдаёт лизу без
 * кандидата, тесты, не проверяющие само отсутствие прокси, заводят узел явно.
 */
function activeDocumentDownloadProxy(): Proxy
{
    return Proxy::factory()->withScopes([ProxyScope::DocumentDownload])->create();
}

test('download первого файла создаёт версию, кладёт файл в storage и пишет событие document_added', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => Http::response(body: sampleDocumentContent(), status: 200)]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeInstanceOf(DocumentVersion::class)
        ->and($version->content_hash)->toBe(hash('sha256', sampleDocumentContent()))
        ->and($version->filename)->toBe($document->title);

    Storage::disk('s3')->assertExists($version->storage_path);

    expect($document->refresh()->status)->toBe(DocumentStatus::Downloaded);

    expect(ProcurementEvent::query()
        ->where('procurement_id', $document->procurement_id)
        ->where('event_type', ProcurementEventType::DocumentAdded)
        ->count())->toBe(1);
});

test('download того же контента повторно — no-op, версия не дублируется', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => Http::response(body: sampleDocumentContent(), status: 200)]);

    app(DocumentDownloader::class)->download($document);
    app(DocumentDownloader::class)->download($document);

    expect(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1);
});

test('download иного контента создаёт новую версию и пишет событие document_replaced', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);

    Http::fake(['*filestore*' => Http::sequence()
        ->push(body: sampleDocumentContent('v1'), status: 200)
        ->push(body: sampleDocumentContent('v2'), status: 200),
    ]);

    app(DocumentDownloader::class)->download($document);
    app(DocumentDownloader::class)->download($document);

    expect(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(2);

    expect(ProcurementEvent::query()
        ->where('procurement_id', $document->procurement_id)
        ->where('event_type', ProcurementEventType::DocumentReplaced)
        ->count())->toBe(1);
});

test('download при ошибке сети записывает error-блок и не бросает исключение', function () {
    Sleep::fake();
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => Http::response(body: 'server error', status: 500)]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeNull();

    $document->refresh();

    expect($document->attempts)->toBe(1)
        ->and($document->last_error)->not->toBeNull()
        ->and($document->next_retry_at)->not->toBeNull()
        ->and($document->failed_permanently)->toBeFalse();
});

test('download без source_url возвращает null без побочных эффектов', function () {
    $document = Document::factory()->create(['source_url' => null]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeNull();
    expect($document->refresh()->attempts)->toBe(0);
});

test('download без активного прокси скоупа document_download пишет error-блок и не бросает исключение', function () {
    Sleep::fake();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => Http::response(body: sampleDocumentContent(), status: 200)]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeNull();

    $document->refresh();

    expect($document->attempts)->toBe(1)
        ->and($document->last_error)->not->toBeNull();

    Http::assertNothingSent();
});
