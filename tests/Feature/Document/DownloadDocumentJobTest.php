<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Jobs\Procurement\DownloadDocumentJob;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Document\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function downloadJobFixture(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
}

test('due download job скачивает discovered-документ через единый downloader route', function () {
    Storage::fake('s3');
    Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
    $document = Document::factory()->create([
        'source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=due-download',
        'status' => DocumentStatus::Discovered,
    ]);
    Http::fake(['*filestore*' => Http::response(body: downloadJobFixture(), status: 200)]);

    (new DownloadDocumentJob(documentId: $document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Downloaded)
        ->and($document->locked_until)->toBeNull();
});

test('due download job безопасно пропускает документ без download input', function () {
    $document = Document::factory()->create([
        'source_url' => null,
        'status' => DocumentStatus::Discovered,
    ]);

    (new DownloadDocumentJob(documentId: $document->id))->handle();

    expect($document->refresh()->status)->toBe(DocumentStatus::Discovered)
        ->and($document->locked_until)->toBeNull();
});
