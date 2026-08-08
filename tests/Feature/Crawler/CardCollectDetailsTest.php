<?php

declare(strict_types=1);

use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Eis\CsvSourceMethod;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;
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

    Storage::disk('s3')->assertExists("raw/html/{$registry->id}/0338200009125000001/common-info.html.gz");
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

    Storage::disk('s3')->assertMissing("raw/html/{$registry->id}/0338200009125000001/common-info.html.gz");
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
        ->with('Резолвер прокси: для карточки нет активной привязки, запрос уходит напрямую', Mockery::any())
        ->atLeast()->once();
});
