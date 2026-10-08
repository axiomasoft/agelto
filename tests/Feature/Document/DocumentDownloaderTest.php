<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\DocumentDownloader;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    return Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
}

test('download первого файла создаёт версию, кладёт файл в storage и пишет событие document_added', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => fn () => Http::response(body: sampleDocumentContent(), status: 200)]);

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
    Http::fake(['*filestore*' => fn () => Http::response(body: sampleDocumentContent(), status: 200)]);

    app(DocumentDownloader::class)->download($document);
    app(DocumentDownloader::class)->download($document);

    expect(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1);
});

test('same-hash retry восстанавливает потерянный object без дублирования версии или события', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=heal-same-hash']);
    Http::fake(['*filestore*' => fn () => Http::response(body: sampleDocumentContent(), status: 200)]);

    $version = app(DocumentDownloader::class)->download($document);
    Storage::disk('s3')->delete($version->storage_path);

    app(DocumentDownloader::class)->download($document);

    Storage::disk('s3')->assertExists($version->storage_path);
    expect(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $document->procurement_id)->count())->toBe(1);
});

test('same-hash retry исправляет повреждённый canonical object без дублирования версии или события', function (): void {
    Sleep::fake();
    $disk = Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=heal-corrupt-hash']);
    $content = sampleDocumentContent();
    Http::fake(['*filestore*' => fn () => Http::response(body: $content, status: 200)]);

    $version = app(DocumentDownloader::class)->download($document);
    expect($version)->not->toBeNull();
    $disk->put($version->storage_path, 'partial');

    $retried = app(DocumentDownloader::class)->download($document);

    expect($retried?->id)->toBe($version->id)
        ->and($disk->get($version->storage_path))->toBe($content)
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $document->procurement_id)->count())->toBe(1)
        ->and(collect($disk->allFiles())->contains(fn (string $file): bool => str_contains($file, '.staging.')))->toBeFalse();
});

test('false staging write не создаёт version или event и не удаляет canonical object', function (): void {
    Sleep::fake();
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=false-write']);
    Http::fake(['*filestore*' => fn () => Http::response(body: sampleDocumentContent(), status: 200)]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->with(
        Mockery::pattern('/\.staging\./'),
        Mockery::on(static fn (mixed $stream): bool => is_resource($stream)),
    )->andReturnFalse();
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnFalse();
    $disk->shouldNotReceive('delete');
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    expect(app(DocumentDownloader::class)->download($document))->toBeNull()
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(0)
        ->and(ProcurementEvent::query()->where('procurement_id', $document->procurement_id)->count())->toBe(0);
});

test('storage exception не создаёт version или event и не удаляет canonical object', function (): void {
    Sleep::fake();
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=exception-write']);
    Http::fake(['*filestore*' => fn () => Http::response(body: sampleDocumentContent(), status: 200)]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->with(
        Mockery::pattern('/\.staging\./'),
        Mockery::on(static fn (mixed $stream): bool => is_resource($stream)),
    )->andThrow(new RuntimeException('disk unavailable'));
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnFalse();
    $disk->shouldNotReceive('delete');
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    expect(app(DocumentDownloader::class)->download($document))->toBeNull()
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(0)
        ->and(ProcurementEvent::query()->where('procurement_id', $document->procurement_id)->count())->toBe(0);
});

test('concurrent canonical winner is verified before one version and event are finalized', function (): void {
    Sleep::fake();
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=concurrent-winner']);
    $content = sampleDocumentContent();
    $hash = hash('sha256', $content);
    $canonicalPath = 'documents/'.substr($hash, 0, 2)."/{$hash}";
    Http::fake(['*filestore*' => fn () => Http::response(body: $content, status: 200)]);
    $streamOf = static function () use ($content): mixed {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    };
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->with(Mockery::pattern('/\.staging\./'), Mockery::type('resource'))->andReturnTrue();
    $disk->shouldReceive('readStream')->twice()->with(Mockery::pattern('/\.staging\./'))->andReturnUsing($streamOf);
    $disk->shouldReceive('exists')->once()->with($canonicalPath)->andReturnFalse();
    $disk->shouldReceive('writeStream')->once()->with($canonicalPath, Mockery::type('resource'))->andReturnFalse();
    $disk->shouldReceive('readStream')->twice()->with($canonicalPath)->andReturnUsing($streamOf);
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnTrue();
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version?->storage_path)->toBe($canonicalPath)
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $document->procurement_id)->count())->toBe(1);
});

test('два прямых скачивания иного контента создают новую версию и document_replaced', function () {
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
    Http::fake(['*filestore*' => fn () => Http::response(body: 'server error', status: 500)]);

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

test('download без активной proxy-привязки идёт напрямую и пишет это в лог', function () {
    Sleep::fake();
    Storage::fake('s3');
    Log::spy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => fn () => Http::response(body: sampleDocumentContent(), status: 200)]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeInstanceOf(DocumentVersion::class)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded);

    expect(Fetch::query()->where('stage', 'document')->sole()->proxy_source?->value)->toBe('direct');

    Http::assertSentCount(1);
    Log::shouldHaveReceived('info')
        ->with('Резолвер прокси: прямой маршрут', Mockery::any())
        ->once();
});

test('download отклоняет loopback до HTTP sink', function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    $document = Document::factory()->create(['source_url' => 'https://127.0.0.1/filestore?uid=ssrf']);

    expect(app(DocumentDownloader::class)->download($document))->toBeNull()
        ->and($document->fresh()->attempts)->toBe(1);

    Http::assertNothingSent();
});

test('download повторно валидирует redirect target и не следует на loopback', function (): void {
    Sleep::fake();
    activeDocumentDownloadProxy();
    $source = 'https://zakupki.gov.ru/filestore?uid=redirect';
    $document = Document::factory()->create(['source_url' => $source]);
    Http::fake([
        $source => fn () => Http::response('', 302, ['Location' => 'https://127.0.0.1/private']),
    ]);

    expect(app(DocumentDownloader::class)->download($document))->toBeNull()
        ->and($document->fresh()->attempts)->toBe(1);

    Http::assertSentCount(1);
});

test('download останавливает фактический поток сверх лимита без доверия к Content-Length', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    config(['crawler.documents_max_bytes' => 8]);
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore?uid=large']);
    Http::fake(['*filestore*' => fn () => Http::response('123456789', 200, ['Content-Length' => '1'])]);

    expect(app(DocumentDownloader::class)->download($document))->toBeNull()
        ->and($document->fresh()->attempts)->toBe(1);

    expect(DocumentVersion::query()->where('document_id', $document->id)->exists())->toBeFalse();
});

test('повтор после 5xx берёт слот бюджета площадки и пишется в журнал запросов', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    config(['crawler.stages.document.retries' => 3]);
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => Http::sequence()
        ->push(body: 'busy', status: 503)
        ->push(body: sampleDocumentContent(), status: 200)]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeInstanceOf(DocumentVersion::class)
        ->and(Fetch::query()->where('stage', 'document')->orderBy('id')->pluck('http_status')->all())->toBe([503, 200]);
    Http::assertSentCount(2);
});

test('исчерпанные повторы 429 не превышают настроенное число запросов', function (): void {
    Sleep::fake();
    activeDocumentDownloadProxy();
    config(['crawler.stages.document.retries' => 2]);
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=aaaa-1111']);
    Http::fake(['*filestore*' => fn () => Http::response(body: 'slow down', status: 429)]);

    expect(app(DocumentDownloader::class)->download($document))->toBeNull();

    Http::assertSentCount(2);
    expect(Fetch::query()->where('stage', 'document')->count())->toBe(2);
});

test('единый deadline прекращает повторы 503 без публикации частичного документа', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    config(['crawler.stages.document.min_pause_ms' => 0, 'crawler.stages.document.max_pause_ms' => 0, 'crawler.stages.document.retry_delay_ms' => 5000]);
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=deadline']);
    Http::fake(['*' => fn () => Http::response('unavailable', 503)]);
    expect(app(DocumentDownloader::class)->download($document, deadlineAt: microtime(true) + 0.1))->toBeNull();
    Http::assertSentCount(1);
    expect($document->refresh()->attempts)->toBe(1)
        ->and($document->next_retry_at)->not->toBeNull()
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

test('цепочка redirect закрывает каждый поток и расходует HTTP бюджет на каждый переход', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=loop']);
    $streams = [];
    Http::fake(['*' => function () use (&$streams) {
        $stream = Utils::streamFor('redirect body');
        $streams[] = $stream;

        return Http::response($stream, 302, ['Location' => '/filestore/file.html?uid=loop']);
    }]);
    expect(app(DocumentDownloader::class)->download($document))->toBeNull();
    Http::assertSentCount(4);
    expect(Fetch::query()->where('procurement_id', $document->procurement_id)->count())->toBe(4)
        ->and(collect($streams)->every(fn ($stream) => ! $stream->isReadable()))->toBeTrue()
        ->and(DocumentVersion::query()->count())->toBe(0);
});

test('deadline на проверке canonical storage не публикует версию и удаляет staging', function (): void {
    Sleep::fake();
    activeDocumentDownloadProxy();
    config(['crawler.stages.document.min_pause_ms' => 0, 'crawler.stages.document.max_pause_ms' => 0]);
    $document = Document::factory()->create(['source_url' => 'https://zakupki.gov.ru/filestore?uid=storage-deadline']);
    $content = sampleDocumentContent();
    $hash = hash('sha256', $content);
    $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";
    Http::fake(['*' => fn () => Http::response($content, 200)]);
    $streamOf = static function () use ($content) {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    };
    $deadline = microtime(true) + 1;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->once()->with(Mockery::pattern('/\.staging\./'), Mockery::type('resource'))->andReturnTrue();
    $disk->shouldReceive('readStream')->twice()->with(Mockery::pattern('/\.staging\./'))->andReturnUsing($streamOf);
    $disk->shouldReceive('exists')->once()->with($path)->andReturnFalse();
    $disk->shouldReceive('writeStream')->once()->with($path, Mockery::type('resource'))->andReturnTrue();
    $disk->shouldReceive('readStream')->once()->with($path)->andReturnUsing(static function () use ($deadline, $streamOf) {
        usleep((int) (max(0, $deadline - microtime(true)) * 1_000_000) + 10_000);

        return $streamOf();
    });
    $disk->shouldReceive('exists')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with(Mockery::pattern('/\.staging\./'))->andReturnTrue();
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
    expect(app(DocumentDownloader::class)->download($document, deadlineAt: $deadline))->toBeNull()
        ->and($document->refresh()->attempts)->toBe(1)
        ->and($document->next_retry_at)->not->toBeNull()
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(0);
});
