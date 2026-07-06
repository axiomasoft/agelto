<?php

declare(strict_types=1);

use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Document;
use App\Models\Procurement\EisDetails;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;
use App\Models\Procurement\Registry;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

function detailAnalysisRegistry(): Registry
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

function detailAnalysisEventJournalHtml(): string
{
    // `prc_events` партиционирована помесячно от момента прогона миграции (см.
    // `EventJournalProcessorTest`) — сдвигаем даты фикстуры на текущий месяц теста.
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/event-journal.html'));
    $html = str_replace('30.06.2026 08:22', now()->startOfMonth()->addDay()->format('d.m.Y H:i'), $html);

    return str_replace('11.07.2026 07:00', now()->startOfMonth()->addDays(2)->format('d.m.Y H:i'), $html);
}

function fakeDetailAnalysisHttp(): void
{
    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: detailAnalysisEventJournalHtml(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: CardHtmlFixture::supplierResults(), status: 200),
        '*filestore*' => Http::response(body: 'binary-content', status: 200),
    ]);
}

test('run наполняет все вкладки-таблицы и ставит detail_fetched_at', function () {
    Sleep::fake();
    Storage::fake('s3');
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    $procurement->refresh();

    expect($procurement->detail_fetched_at)->not->toBeNull()
        ->and($procurement->detail_failed)->toBeFalse()
        ->and($procurement->detail_last_error)->toBeNull();

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
    expect(ProcurementParty::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
    expect(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(2);
    expect(ProcurementEvent::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
    expect(Protocol::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
});

test('run скачивает документы, снятые с вкладки documents', function () {
    Sleep::fake();
    Storage::fake('s3');
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    expect(Document::query()->where('procurement_id', $procurement->id)->where('status', DocumentStatus::Downloaded)->count())->toBe(2);
});

test('падение снятия карточки уходит в error-блок, detail_fetched_at не ставится, другие закупки не затронуты', function () {
    Sleep::fake();
    Storage::fake('s3');
    $registry = detailAnalysisRegistry();
    $healthy = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200001234000001',
        'procedure_type' => 'electronic_auction',
        'status' => ProcurementStatus::Bidding,
    ]);
    $broken = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200009999000009',
        'procedure_type' => 'unmapped_procedure_type',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeDetailAnalysisHttp();

    $processor = app(DetailAnalysisProcessor::class);
    $processor->run(procurement: $healthy);
    $processor->run(procurement: $broken);

    $healthy->refresh();
    $broken->refresh();

    expect($healthy->detail_fetched_at)->not->toBeNull();

    expect($broken->detail_fetched_at)->toBeNull()
        ->and($broken->detail_attempts)->toBe(1)
        ->and($broken->detail_last_error)->not->toBeNull()
        ->and($broken->detail_failed)->toBeFalse()
        ->and($broken->detail_next_retry_at)->not->toBeNull();
});

test('исчерпание попыток ставит detail_failed', function () {
    Sleep::fake();
    Storage::fake('s3');
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'source_number' => '0173200009999000009',
        'procedure_type' => 'unmapped_procedure_type',
        'status' => ProcurementStatus::Bidding,
        'detail_attempts' => (int) config('crawler.detail_max_attempts', 5) - 1,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    expect($procurement->refresh()->detail_failed)->toBeTrue()
        ->and($procurement->detail_next_retry_at)->toBeNull();
});
