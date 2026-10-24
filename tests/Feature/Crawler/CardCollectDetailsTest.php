<?php

declare(strict_types=1);

use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

/**
 * Активный узел скоупа `crawl_detail` (research/02 §9.1, P1.6) — резолвер не выдаёт лизу без
 * кандидата, `collectDetails()` теперь резолвит прокси на каждую вкладку карточки.
 */
function activeDetailProxy(): Proxy
{
    return Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create();
}

function fakeCardRegistry(): Registry
{
    return Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
            'card_url_templates' => [
                'ea20' => [
                    'common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={regNumber}',
                    'documents' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html?regNumber={regNumber}',
                    'event-journal' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/event-journal.html?regNumber={regNumber}',
                    'supplier-results' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/supplier-results.html?regNumber={regNumber}',
                ],
            ],
        ],
    ]);
}

function fakeCardHttp(): void
{
    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: CardHtmlFixture::eventJournal(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: CardHtmlFixture::supplierResults(), status: 200),
    ]);
}

test('таймаут карточки сохраняет числовые фазы транспорта без URL и credentials', function (int $httpCode, int $receivedBytes) {
    Sleep::fake();
    Log::spy();
    activeDetailProxy();
    $registry = fakeCardRegistry();
    $registry->platform->update(['throttle' => ['stages' => ['detail' => ['retries' => 1]]]]);
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    Http::fake(function () use ($httpCode, $receivedBytes) {
        throw new ConnectException('Operation timed out', new PsrRequest('GET', 'https://example.invalid/?token=secret'), null, [
            'errno' => 28, 'connect_time' => 0.12, 'appconnect_time' => 0.8,
            'pretransfer_time' => 0.81, 'starttransfer_time' => 0.0, 'total_time' => 30.0,
            'http_code' => $httpCode, 'size_download' => $receivedBytes,
            'url' => 'https://user:password@example.invalid/?token=secret',
            'error' => 'proxy credentials', 'certinfo' => ['private certificate details'],
        ]);
    });

    expect(fn () => app(CsvSourceMethod::class)->collectDetails('0338200009125000001', $registry))
        ->toThrow(ConnectionException::class);

    Log::shouldHaveReceived('info')->with('Crawler outbound attempt', Mockery::on(function (array $context) use ($httpCode, $receivedBytes): bool {
        $transport = $context['transport'] ?? [];

        return $context['reason'] === 'network_timeout'
            && $context['http_status'] === null
            && $transport['errno'] === 28
            && $transport['http_code'] === $httpCode
            && $transport['size_download'] === $receivedBytes
            && $transport['appconnect_time'] === 0.8
            && $transport['starttransfer_time'] === 0.0
            && ! str_contains(json_encode($context), 'secret')
            && ! str_contains(json_encode($context), 'password')
            && ! array_key_exists('error', $transport)
            && ! array_key_exists('certinfo', $transport);
    }))->once();
    expect(Fetch::query()->count())->toBe(0);
})->with(['без HTTP-ответа' => [0, 0], 'частичное тело HTTP 200' => [200, 12415]]);

test('collectDetails отправляет карточный URL с query-строкой regNumber, а не голый endpoint (Д-8)', function () {
    Sleep::fake();
    activeDetailProxy();
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeCardHttp();

    app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    Http::assertSent(fn ($request) => (string) $request->url() === 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=0338200009125000001');
    Http::assertSent(fn ($request) => (string) $request->url() === 'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html?regNumber=0338200009125000001');
    Http::assertSent(fn ($request) => (string) $request->url() === 'https://zakupki.gov.ru/epz/order/notice/ea20/view/event-journal.html?regNumber=0338200009125000001');
    Http::assertSent(fn ($request) => (string) $request->url() === 'https://zakupki.gov.ru/epz/order/notice/ea20/view/supplier-results.html?regNumber=0338200009125000001');
});

test('collectDetails возвращает ProcurementDetails с сырым HTML всех вкладок завершённой закупки', function () {
    Sleep::fake();
    activeDetailProxy();
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeCardHttp();

    $details = app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    expect($details)->toBeInstanceOf(ProcurementDetails::class)
        ->and($details->procedureUrlType)->toBe('ea20')
        ->and($details->commonInfoHtml)->toBe(CardHtmlFixture::commonInfo())
        ->and($details->documentsHtml)->toBe(CardHtmlFixture::documents())
        ->and($details->eventJournalHtml)->toBe(CardHtmlFixture::eventJournal())
        ->and($details->supplierResultsHtml)->toBe(CardHtmlFixture::supplierResults());
});

test('collectDetails не забирает supplier-results для незавершённой закупки', function () {
    Sleep::fake();
    activeDetailProxy();
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeCardHttp();

    $details = app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    expect($details->supplierResultsHtml)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'supplier-results.html'));
});

test('каждый transport retry карточки заново расходует общий request budget', function (): void {
    Sleep::fake();
    activeDetailProxy();
    $registry = fakeCardRegistry();
    $registry->platform->update([
        'base_url' => 'https://zakupki.gov.ru/',
        'throttle' => [
            'requestsPerMinuteBase' => 1,
            'requestsPerMinutePerProxy' => 0,
            'maxWaitSeconds' => 0,
            'stages' => [
                'detail' => [
                    'minPauseMs' => 0,
                    'maxPauseMs' => 0,
                    'retries' => 2,
                    'retryDelayMs' => 0,
                    'timeoutSeconds' => 30,
                ],
            ],
        ],
    ]);
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    Http::fakeSequence()
        ->push('temporary upstream failure', 503)
        ->push(CardHtmlFixture::commonInfo(), 200);

    expect(fn () => app(CsvSourceMethod::class)->collectDetails(
        registryNumber: '0338200009125000001',
        registry: $registry,
    ))->toThrow(PlatformBudgetExhaustedException::class);

    Http::assertSentCount(1);
});

test('collectDetails кладёт сырой HTML в storage gzip при включённом retention', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailProxy();
    config(['crawler.raw_html_retention_enabled' => true]);
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeCardHttp();

    app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    $artifactRef = Fetch::query()
        ->where('stage', CrawlStage::Detail->value)
        ->whereJsonContains('request_params->tab', 'common-info')
        ->sole()
        ->artifact_ref;

    expect($artifactRef)->toMatch("#^raw/html/{$registry->id}/0338200009125000001/common-info/[a-f0-9]{64}/[a-f0-9]{64}\\.html\\.gz$#");
    Storage::disk('s3')->assertExists($artifactRef);
});

test('collectDetails не пишет в storage когда retention выключен', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailProxy();
    config(['crawler.raw_html_retention_enabled' => false]);
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeCardHttp();

    app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    expect(Fetch::query()
        ->where('stage', CrawlStage::Detail->value)
        ->whereJsonContains('request_params->tab', 'common-info')
        ->sole()
        ->artifact_ref)
        ->toBeNull();
});

test('collectDetails бросает исключение при отсутствии URL-шаблона для типа процедуры', function () {
    Sleep::fake();
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['procedure_url_types' => []],
    ]);
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);

    app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);
})->throws(CardUrlTemplateMissingException::class);

test('collectDetails у типа без вкладки supplier-results (маркер null, D121) снимает три вкладки без исключения', function () {
    Sleep::fake();
    activeDetailProxy();
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['Электронный аукцион на оказание услуг или выполнение работ по капитальному ремонту общего имущества в многоквартирном доме' => 'ea615'],
            'card_url_templates' => [
                'ea615' => [
                    'common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea615/view/common-info.html?regNumber={regNumber}',
                    'documents' => 'https://zakupki.gov.ru/epz/order/notice/ea615/view/documents.html?regNumber={regNumber}',
                    'event-journal' => 'https://zakupki.gov.ru/epz/order/notice/ea615/view/event-journal.html?regNumber={regNumber}',
                    'supplier-results' => null,
                ],
            ],
        ],
    ]);
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион на оказание услуг или выполнение работ по капитальному ремонту общего имущества в многоквартирном доме',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeCardHttp();

    $details = app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    expect($details->commonInfoHtml)->toBe(CardHtmlFixture::commonInfo())
        ->and($details->documentsHtml)->toBe(CardHtmlFixture::documents())
        ->and($details->eventJournalHtml)->toBe(CardHtmlFixture::eventJournal())
        ->and($details->supplierResultsHtml)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'supplier-results.html'));
});

test('collectDetails без активной proxy-привязки идёт напрямую и пишет это в лог', function (): void {
    Sleep::fake();
    Log::spy();
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeCardHttp();

    app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    Http::assertSentCount(4);
    Log::shouldHaveReceived('info')
        ->with('Резолвер прокси: прямой маршрут', Mockery::any())
        ->atLeast()->once();
});
