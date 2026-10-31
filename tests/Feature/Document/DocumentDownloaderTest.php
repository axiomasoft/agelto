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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

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

/** Actual network scenarios use the guarded wire fixture; storage cases retain a logical-call double. */
function documentNetworkScenario(Closure $scenario): void
{
    PermitFixtures::clean();
    $wire = new WireFixture(443);
    try {
        $registry = CallerFixtures::platform($wire);
        Storage::fake('s3');
        $document = Document::factory()->create(['source_url' => $wire->url('/document', true)->reveal(), 'platform_id' => $registry->platform_id]);
        $scenario($wire, $document);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
}

beforeEach(fn () => LegacyBusinessNetwork::install());

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

test('download при ошибке сети записывает error-блок и не бросает исключение', function (): void {
    documentNetworkScenario(function ($wire, $document): void {
        $wire->respond('/document', 'busy', 503);
        $before = $document->refresh()->getRawOriginal();
        expect(app(DocumentDownloader::class)->download($document))->toBeNull()
            ->and($document->refresh()->getRawOriginal())->toEqual($before)
            ->and($wire->receipts())->toHaveCount(3)
            ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(0);
    });
});

test('download без source_url возвращает null без побочных эффектов', function () {
    $document = Document::factory()->create(['source_url' => null]);

    $version = app(DocumentDownloader::class)->download($document);

    expect($version)->toBeNull();
    expect($document->refresh()->attempts)->toBe(0);
});

test('download без активной proxy-привязки идёт напрямую и пишет это в лог', function (): void {
    documentNetworkScenario(function ($wire, $document): void {
        $wire->respond('/document', sampleDocumentContent(), headers: ['Content-Type' => 'application/pdf']);
        $version = app(DocumentDownloader::class)->download($document);
        $route = DB::table('prc_network_routes')->where('kind', 'direct')->sole();
        expect($version)->toBeInstanceOf(DocumentVersion::class)
            ->and(DB::table('prc_request_attempts')->value('route_id'))->toBe($route->id)
            ->and(Fetch::query()->count())->toBe(0)->and($wire->receipts())->toHaveCount(1);
    });
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
    documentNetworkScenario(function ($wire, $document): void {
        $wire->respond('/document', '', 302, ['Location' => 'https://127.0.0.1/private']);
        expect(app(DocumentDownloader::class)->download($document))->toBeNull()
            ->and($document->refresh()->attempts)->toBe(0)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DocumentVersion::query()->count())->toBe(0);
    });
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
    documentNetworkScenario(function ($wire, $document): void {
        $wire->respond('/document', 'busy', 503);
        $wire->respond('/document', sampleDocumentContent(), headers: ['Content-Type' => 'application/pdf']);
        $value = app(DocumentDownloader::class)->download($document);
        expect($value)->toBeInstanceOf(DocumentVersion::class)
            ->and(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('status')->all())->toBe([503, 200])
            ->and(DB::table('prc_network_permits')->count())->toBe(2)
            ->and($wire->receipts())->toHaveCount(2)->and(Fetch::query()->count())->toBe(0);
    });
});

test('исчерпанные повторы 429 не превышают настроенное число запросов', function (): void {
    documentNetworkScenario(function ($wire, $document): void {
        $db = DB::table('prc_source_targets')->where('capability', 'download')
            ->where('platform_id', $document->platform_id)
            ->where('canonical_origin', $wire->url('/document', true)->origin->value())
            ->whereNull('retired_at');
        $row = $db->sole();
        $policy = json_decode($row->endpoint_policy, true, flags: JSON_THROW_ON_ERROR);
        $policy['profiles'][0]['attempts'] = 2;
        $policy['profiles'][0]['delays_ms'] = [0];
        $db->update(['endpoint_policy' => json_encode($policy, JSON_THROW_ON_ERROR)]);
        $wire->respond('/document', 'slow', 429, ['Retry-After' => '0']);
        expect(app(DocumentDownloader::class)->download($document))->toBeNull()
            ->and($document->refresh()->attempts)->toBe(0)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)
            ->and(DB::table('prc_observations')->value('reason_code'))->toBe('http_429');
    });
});

test('единый deadline прекращает повторы 503 без публикации частичного документа', function (): void {
    documentNetworkScenario(function ($wire, $document): void {
        $wire->respond('/document', 'unavailable', 503);
        expect(app(DocumentDownloader::class)->download($document, deadlineAt: microtime(true) + 0.1))->toBeNull()
            ->and($wire->receipts())->toBe([])->and($document->refresh()->attempts)->toBe(0)
            ->and(DocumentVersion::query()->count())->toBe(0)->and(Storage::disk('s3')->allFiles())->toBe([]);
    });
});

test('цепочка redirect закрывает каждый поток и расходует HTTP бюджет на каждый переход', function (): void {
    documentNetworkScenario(function ($wire, $document): void {
        $wire->respond('/document', 'redirect body', 302, ['Location' => '/document']);
        expect(app(DocumentDownloader::class)->download($document))->toBeNull()
            ->and($wire->receipts())->toHaveCount(4)
            ->and(DB::table('prc_network_permits')->count())->toBe(4)
            ->and(DB::table('prc_request_attempts')->whereNotNull('finished_at')->count())->toBe(4)
            ->and(DocumentVersion::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles('process-core/http'))->toBe([]);
    });
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

test('connection failure preserves the document item budget even at its last remaining attempt', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDocumentDownloadProxy();
    config(['crawler.documents_max_attempts' => 5]);
    $document = Document::factory()->create([
        'source_url' => 'https://zakupki.gov.ru/filestore/file.html?uid=connection-failure',
        'attempts' => 4, 'failed_permanently' => false, 'last_error' => 'prior item evidence',
    ]);
    $before = $document->refresh()->getRawOriginal();
    $calls = 0;
    Http::fake(function () use (&$calls): never {
        $calls++;
        throw new ConnectionException('fixture connection failed');
    });
    expect(app(DocumentDownloader::class)->download($document))->toBeNull()
        ->and($calls)->toBe(1)
        ->and($document->refresh()->getRawOriginal())->toEqual($before)
        ->and(DocumentVersion::query()->where('document_id', $document->id)->count())->toBe(0);
})->group('t03', 'p26');
