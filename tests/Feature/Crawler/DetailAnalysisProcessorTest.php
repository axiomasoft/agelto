<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\EisDetails;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;
use App\Services\Procurement\DetailRefetchSelector;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

/**
 * Активные узлы скоупов `crawl_detail` и `document_download` (research/02 §9.1, P1.6) —
 * стадия детального анализа снимает и вкладку карточки, и файлы документов.
 */
function activeDetailAnalysisProxies(): void
{
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create();
    Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
}

function detailAnalysisRegistry(): Registry
{
    return Registry::factory()->eis()->create([
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

function detailAnalysisEventJournalHtml(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/Crawler/Card/event-journal.html'));
}

function detailAnalysisSupplierResultsHtml(): string
{
    return CardHtmlFixture::supplierResults();
}

test('supplier-results mismatch остаётся retryable и следующий полный проход сходится', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    $supplierAttempts = 0;
    Http::fake([
        '*view/common-info.html*' => Http::response(CardHtmlFixture::commonInfo(), 200),
        '*view/documents.html*' => Http::response(CardHtmlFixture::documents(), 200),
        '*view/event-journal.html*' => Http::response(detailAnalysisEventJournalHtml(), 200),
        '*view/supplier-results.html*' => function () use (&$supplierAttempts) {
            $supplierAttempts++;

            return $supplierAttempts === 1
                ? Http::response('<html>temporary protection page</html>', 200)
                : Http::response(detailAnalysisSupplierResultsHtml(), 200);
        },
    ]);

    expect(app(DetailAnalysisProcessor::class)->run(procurement: $procurement))->toBeFalse()
        ->and($procurement->fresh()->detail_fetched_at)->toBeNull()
        ->and($procurement->detail_attempts)->toBe(1)
        ->and($procurement->detail_next_retry_at)->not->toBeNull();

    $this->travel((int) config('crawler.detail_retry_backoff_minutes', 15) + 1)->minutes();

    $result = app(DetailAnalysisProcessor::class)->run(procurement: $procurement->fresh());

    expect($result)->toBeTrue((string) $procurement->fresh()->detail_last_error)
        ->and($procurement->fresh()->detail_fetched_at)->not->toBeNull()
        ->and(Protocol::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
});

function fakeDetailAnalysisHttp(): void
{
    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: detailAnalysisEventJournalHtml(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: detailAnalysisSupplierResultsHtml(), status: 200),
        '*filestore*' => Http::response(body: 'binary-content', status: 200),
    ]);
}

test('run наполняет все вкладки-таблицы и ставит detail_fetched_at', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Awarded,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    $procurement->refresh();

    expect($procurement->detail_fetched_at)->not->toBeNull()
        ->and($procurement->detail_failed)->toBeFalse()
        ->and($procurement->detail_last_error)->toBeNull();

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
    // `organizations-block`/`table.parties-table` отсутствует на реальной вкладке common-info
    // (P4.14, `findings/P4-card-layout.md` §3) — PartiesParser честно не находит сторон,
    // см. Pending Work item'а P4.14 ([UNVERIFIED]).
    expect(ProcurementParty::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse();
    // 6 документов (не 2) — реальная вкладка documents несёт 6 вложений (P4.13, findings/
    // P4-card-layout.md §2); эта ассерция уже была неверна ДО P4.14 (не обнаруживалась —
    // тест падал раньше, на detail_fetched_at), правка мимоходом при первом реальном достижении.
    expect(Document::query()->where('procurement_id', $procurement->id)->count())->toBe(6);
    expect(ProcurementEvent::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
    // Даже несостоявшаяся закупка содержит опубликованный протокол; участники и award
    // при этом отсутствуют (D128).
    expect(Protocol::query()->where('procurement_id', $procurement->id)->exists())->toBeTrue();
});

test('run индексирует документы как discovered без inline-скачивания', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    expect(Document::query()->where('procurement_id', $procurement->id)->where('status', DocumentStatus::Discovered)->count())->toBe(6);
});

test('incomplete documents page не снимает ранее известные вложения', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: '<html><body>Защитная страница</body></html>', status: 200),
        '*view/event-journal.html*' => Http::response(body: detailAnalysisEventJournalHtml(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: detailAnalysisSupplierResultsHtml(), status: 200),
        '*filestore*' => Http::response(body: 'binary-content', status: 200),
    ]);

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    expect(Document::query()->where('procurement_id', $procurement->id)->whereNull('removed_at')->count())->toBe(6);
});

test('падение снятия карточки уходит в error-блок, detail_fetched_at не ставится, другие закупки не затронуты', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $healthy = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    $broken = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200009999000009',
        'procedure_type' => 'unmapped_procedure_type',
        'procedure_type_raw' => 'Неизвестный способ вне карты',
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

test('common-info вернул страницу без запрошенного реестрового номера (Д-8) — detail_last_error непуст, detail_fetched_at пуст, prc_eis_details не создана', function () {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    Http::fake([
        '*view/common-info.html*' => Http::response(body: '<html><body>Страница не найдена</body></html>', status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: detailAnalysisEventJournalHtml(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: detailAnalysisSupplierResultsHtml(), status: 200),
        '*filestore*' => Http::response(body: 'binary-content', status: 200),
    ]);

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    $procurement->refresh();

    expect($procurement->detail_fetched_at)->toBeNull()
        ->and($procurement->detail_attempts)->toBe(1)
        ->and($procurement->detail_last_error)->not->toBeNull();

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse();
});

test('исчерпание попыток ставит detail_failed', function () {
    Sleep::fake();
    Storage::fake('s3');
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0173200009999000009',
        'procedure_type' => 'unmapped_procedure_type',
        'procedure_type_raw' => 'Неизвестный способ вне карты',
        'status' => ProcurementStatus::Bidding,
        'detail_attempts' => (int) config('crawler.detail_max_attempts', 5) - 1,
    ]);
    fakeDetailAnalysisHttp();

    app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    expect($procurement->refresh()->detail_failed)->toBeTrue()
        ->and($procurement->detail_next_retry_at)->toBeNull();
});

test('неполные обязательные snapshots сохраняют outcome и возвращаются в due после backoff', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
        'detail_fetched_at' => null,
        'current_revision_detected_at' => now()->subDay(),
    ]);
    Http::fake(['*' => Http::response("<html><body>{$procurement->registry_number} Temporary maintenance</body></html>", 200)]);

    expect(app(DetailAnalysisProcessor::class)->run(procurement: $procurement))->toBeFalse();

    $procurement->refresh();

    expect($procurement->detail_fetched_at)->toBeNull()
        ->and($procurement->detail_attempts)->toBe(1)
        ->and($procurement->detail_parse_outcome)->not->toBeNull()
        ->and($procurement->detail_next_retry_at)->not->toBeNull();

    $this->travel((int) config('crawler.detail_retry_backoff_minutes', 15) + 1)->minutes();

    expect(app(DetailRefetchSelector::class)->due(limit: 10)->pluck('id')->all())
        ->toContain($procurement->id);
});

test('легитимно пустой complete documents snapshot завершает detail без вечного retry', function (): void {
    Sleep::fake();
    Storage::fake('s3');
    activeDetailAnalysisProxies();
    $registry = detailAnalysisRegistry();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
        'status' => ProcurementStatus::Bidding,
    ]);
    Http::fake([
        '*view/common-info.html*' => Http::response(CardHtmlFixture::commonInfo(), 200),
        '*view/documents.html*' => Http::response('<div>0338200009125000001</div><div class="blockFilesTabDocs"></div>', 200),
        '*view/event-journal.html*' => Http::response(detailAnalysisEventJournalHtml(), 200),
        '*view/supplier-results.html*' => Http::response(detailAnalysisSupplierResultsHtml(), 200),
    ]);

    $result = app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    expect($result)->toBeTrue((string) $procurement->fresh()->detail_last_error)
        ->and($procurement->fresh()->detail_fetched_at)->not->toBeNull()
        ->and($procurement->detail_attempts)->toBe(0);
});
