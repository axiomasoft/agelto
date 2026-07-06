<?php

declare(strict_types=1);

use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Registry;
use App\Services\Procurement\Eis\CsvSourceMethod;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

function fakeCardRegistry(): Registry
{
    return Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['electronic_auction' => 'ea20'],
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

test('collectDetails возвращает ProcurementDetails с сырым HTML всех вкладок завершённой закупки', function () {
    Sleep::fake();
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeCardHttp();

    $details = app(CsvSourceMethod::class)->collectDetails(sourceNumber: '0173200001234000001', registry: $registry);

    expect($details)->toBeInstanceOf(ProcurementDetails::class)
        ->and($details->procedureUrlType)->toBe('ea20')
        ->and($details->commonInfoHtml)->toBe(CardHtmlFixture::commonInfo())
        ->and($details->documentsHtml)->toBe(CardHtmlFixture::documents())
        ->and($details->eventJournalHtml)->toBe(CardHtmlFixture::eventJournal())
        ->and($details->supplierResultsHtml)->toBe(CardHtmlFixture::supplierResults());
});

test('collectDetails не забирает supplier-results для незавершённой закупки', function () {
    Sleep::fake();
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeCardHttp();

    $details = app(CsvSourceMethod::class)->collectDetails(sourceNumber: '0173200001234000001', registry: $registry);

    expect($details->supplierResultsHtml)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'supplier-results.html'));
});

test('collectDetails кладёт сырой HTML в storage gzip при включённом retention', function () {
    Sleep::fake();
    Storage::fake('s3');
    config(['crawler.raw_html_retention_enabled' => true]);
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeCardHttp();

    app(CsvSourceMethod::class)->collectDetails(sourceNumber: '0173200001234000001', registry: $registry);

    Storage::disk('s3')->assertExists("raw/html/{$registry->id}/0173200001234000001/common-info.html.gz");
});

test('collectDetails не пишет в storage когда retention выключен', function () {
    Sleep::fake();
    Storage::fake('s3');
    config(['crawler.raw_html_retention_enabled' => false]);
    $registry = fakeCardRegistry();
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeCardHttp();

    app(CsvSourceMethod::class)->collectDetails(sourceNumber: '0173200001234000001', registry: $registry);

    Storage::disk('s3')->assertMissing("raw/html/{$registry->id}/0173200001234000001/common-info.html.gz");
});

test('collectDetails бросает исключение при отсутствии URL-шаблона для типа процедуры', function () {
    Sleep::fake();
    $registry = Registry::factory()->create([
        'code' => 'fz44',
        'settings' => ['procedure_url_types' => []],
    ]);
    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);

    app(CsvSourceMethod::class)->collectDetails(sourceNumber: '0173200001234000001', registry: $registry);
})->throws(CardUrlTemplateMissingException::class);
