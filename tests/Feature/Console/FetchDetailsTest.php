<?php

declare(strict_types=1);

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

function fetchDetailsRegistry(): Registry
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

function fetchDetailsEventJournalHtml(): string
{
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/event-journal.html'));
    $html = str_replace('30.06.2026 08:22', now()->startOfMonth()->addDay()->format('d.m.Y H:i'), $html);

    return str_replace('11.07.2026 07:00', now()->startOfMonth()->addDays(2)->format('d.m.Y H:i'), $html);
}

function fakeFetchDetailsHttp(): void
{
    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: fetchDetailsEventJournalHtml(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: CardHtmlFixture::supplierResults(), status: 200),
        '*filestore*' => Http::response(body: 'binary-content', status: 200),
    ]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
});

test('снимает детальный слой по явному id закупки', function () {
    $registry = fetchDetailsRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeFetchDetailsHttp();

    $this->artisan('crawler:fetch-details', ['procurement' => (string) $procurement->id])
        ->assertExitCode(Command::SUCCESS);

    expect($procurement->refresh()->detail_fetched_at)->not->toBeNull();
});

test('несуществующий id закупки — FAILURE с сообщением об ошибке', function () {
    $this->artisan('crawler:fetch-details', ['procurement' => '999999'])
        ->expectsOutputToContain('не найдена')
        ->assertExitCode(Command::FAILURE);
});

test('без аргумента обрабатывает все due-закупки по DetailRefetchSelector', function () {
    $registry = fetchDetailsRegistry();
    $due = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
        'detail_fetched_at' => null,
    ]);
    $fresh = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200001234000002',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
        'detail_fetched_at' => now(),
        'current_revision_detected_at' => now()->subDay(),
    ]);
    fakeFetchDetailsHttp();

    $this->artisan('crawler:fetch-details')
        ->assertExitCode(Command::SUCCESS);

    expect($due->refresh()->detail_fetched_at)->not->toBeNull();

    $freshFetchedAt = $fresh->detail_fetched_at;
    expect($fresh->refresh()->detail_fetched_at->equalTo($freshFetchedAt))->toBeTrue();
});

test('падение одной закупки не прерывает обработку остальных', function () {
    $registry = fetchDetailsRegistry();
    $healthy = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
        'detail_fetched_at' => null,
    ]);
    $broken = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200009999000009',
        'procedure_type' => 'unmapped_procedure_type',
        'status' => ProcurementStatus::Bidding,
        'detail_fetched_at' => null,
    ]);
    fakeFetchDetailsHttp();

    $this->artisan('crawler:fetch-details')
        ->assertExitCode(Command::SUCCESS);

    // DetailAnalysisProcessor не бросает исключений наружу (error-блок), поэтому
    // командный try/catch здесь не срабатывает — обе закупки обрабатываются, FAILURE
    // не проявляется на уровне команды; ошибка видна в error-блоке `broken`.
    expect($healthy->refresh()->detail_fetched_at)->not->toBeNull()
        ->and($broken->refresh()->detail_fetched_at)->toBeNull()
        ->and($broken->detail_last_error)->not->toBeNull();
});
