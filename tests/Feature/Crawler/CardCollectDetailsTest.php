<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

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

function fakeCardHttp(): void
{
    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: CardHtmlFixture::eventJournal(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: CardHtmlFixture::supplierResults(), status: 200),
    ]);
}

function realCardScenario(): array
{
    PermitFixtures::clean();
    $wire = new WireFixture;
    $registry = CallerFixtures::platform($wire);
    config(['crawler.documents_allowed_hosts' => ['fixture.test', 'zakupki.gov.ru']]);
    $templates = [];
    foreach (['common-info' => CardHtmlFixture::commonInfo(), 'documents' => CardHtmlFixture::documents(),
        'event-journal' => CardHtmlFixture::eventJournal(), 'supplier-results' => CardHtmlFixture::supplierResults()] as $tab => $body) {
        $wire->respond('/'.$tab, $body, headers: ['Content-Type' => 'text/html; charset=UTF-8']);
        $templates[$tab] = $wire->url('/'.$tab.'?regNumber={regNumber}')->reveal();
    }
    $registry->update(['settings' => ['procedure_url_types' => ['Электронный аукцион' => 'ea20'], 'card_url_templates' => ['ea20' => $templates]]]);
    $procurement = Procurement::factory()->for($registry)->create(['registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион', 'status' => ProcurementStatus::Awarded]);

    return [$wire, $registry, $procurement];
}

function closeCardScenario($wire): void
{
    $wire->stop();
    PermitFixtures::clean();
}

beforeEach(fn () => LegacyBusinessNetwork::install());

test('таймаут карточки сохраняет числовые фазы транспорта без URL и credentials', function (int $httpCode, int $receivedBytes) {
    [$wire, $registry, $procurement] = realCardScenario();
    try {
        config(['crawler.eis.card_tabs' => ['common-info']]);
        $settings = $registry->settings;
        $settings['card_url_templates']['ea20']['common-info'] = $wire->url($httpCode === 0 ? '/stall' : '/body-partial-stall')->reveal();
        $registry->update(['settings' => $settings]);
        $targets = [];
        foreach (app(CsvSourceMethod::class)->sourceTargets()->targets as $target) {
            if ($target->capability === 'detail') {
                $profile = array_values($target->profiles)[0];
                $short = RequestProfile::fromArray(array_replace($profile->toArray(), ['connect_seconds' => 1, 'low_speed_seconds' => 1, 'total_seconds' => 2]));
                $target = new SourceTargetDefinition($target->key, $target->capability, $target->origin,
                    [$short], adapterContract: $target->adapterContract);
            }
            $targets[] = $target;
        }
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions('eis', $targets));
        Log::spy();
        expect(fn () => app(CsvSourceMethod::class)->collectDetails($procurement->registry_number, $registry))
            ->toThrow(OutboundUnavailable::class);
        Log::shouldHaveReceived('info')->with('Crawler outbound attempt', Mockery::on(function (array $context) use ($httpCode, $receivedBytes): bool {
            $transport = $context['transport'];

            return $context['reason'] === 'network_timeout' && $context['http_status'] === null
                && $transport['errno'] === 28 && $transport['http_code'] === $httpCode && $transport['size_download'] === $receivedBytes
                && is_float($transport['appconnect_time']) && is_float($transport['starttransfer_time'])
                && array_keys($transport) === ['errno', 'connect_time', 'appconnect_time', 'pretransfer_time', 'starttransfer_time', 'total_time', 'http_code', 'size_download']
                && ! str_contains(json_encode($context), 'regNumber') && ! array_key_exists('error', $transport) && ! array_key_exists('certinfo', $transport);
        }))->once();
        $attempt = DB::table('prc_request_attempts')->sole();
        expect($attempt->outcome)->toBe('timeout')->and($attempt->status)->toBe($httpCode === 0 ? null : $httpCode)
            ->and($attempt->bytes)->toBe($receivedBytes)->and(Fetch::query()->count())->toBe(0);
    } finally {
        closeCardScenario($wire);
    }
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
    [$wire, $registry, $procurement] = realCardScenario();
    try {
        // A real full canary makes the adapter healthy; the next ordinary read retries 503 through the same budget.
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100000, 'burst' => 1]);
        $source = app(CsvSourceMethod::class);
        $source->collectDetails($procurement->registry_number, $registry);
        $wire->respond('/common-info', 'temporary upstream failure', 503);
        $wire->respond('/common-info', CardHtmlFixture::commonInfo(), headers: ['Content-Type' => 'text/html; charset=UTF-8']);
        $details = $source->collectDetails($procurement->registry_number, $registry);
        $attempts = DB::table('prc_request_attempts')->orderBy('request_seq')->get();
        expect($details->validation->requestIds)->toHaveCount(4)->and($attempts)->toHaveCount(9)
            ->and(DB::table('prc_network_permits')->where('state', 'released')->count())->toBe(9)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(2);
        for ($index = 1; $index < 9; $index++) {
            $gap = strtotime($attempts[$index]->sent_at) - strtotime($attempts[$index - 1]->sent_at);
            expect($gap)->toBeGreaterThanOrEqual(0);
        }
        $origin = $wire->url('/')->origin;
        $budget = DB::table('prc_network_budgets')->where('scope_key', $origin->budgetScopeKey())->sole();
        expect(DB::table('prc_network_demands')->where('origin_budget_id', $budget->id)->count())->toBe(9)
            ->and($budget->tat)->not->toBeNull()
            ->and($attempts->pluck('status')->all())->toBe([200, 200, 200, 200, 503, 200, 200, 200, 200]);
    } finally {
        closeCardScenario($wire);
    }
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

    $artifactRef = RawArtifact::query()
        ->where('path', 'like', "raw/html/{$registry->id}/0338200009125000001/common-info/%")->sole()->getAttribute('path');

    expect($artifactRef)->toMatch("#^raw/html/{$registry->id}/0338200009125000001/common-info/[a-f0-9]{64}/[a-f0-9]{64}\\.html\\.gz$#");
    Storage::disk('s3')->assertExists($artifactRef);
    expect(gzdecode(Storage::disk('s3')->get($artifactRef)))->toBe(CardHtmlFixture::commonInfo())
        ->and(Fetch::query()->count())->toBe(0);
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

    expect(RawArtifact::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([])->and(Fetch::query()->count())->toBe(0);
});

test('collectDetails бросает исключение при отсутствии URL-шаблона для типа процедуры', function () {
    Sleep::fake();
    $registry = Registry::factory()->eis()->create([
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
    $registry = Registry::factory()->eis()->create([
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
    [$wire, $registry, $procurement] = realCardScenario();
    try {
        Log::spy();
        app(CsvSourceMethod::class)->collectDetails($procurement->registry_number, $registry);
        expect($wire->receipts())->toHaveCount(4)
            ->and(array_unique(DB::table('prc_request_attempts')->pluck('route_id')->all()))
            ->toBe([DB::table('prc_network_routes')->where('kind', 'direct')->sole()->id]);
        Log::shouldHaveReceived('info')->with('Crawler outbound attempt', Mockery::on(
            fn (array $context): bool => is_string($context['route_id']) && $context['http_status'] === 200))->times(4);
    } finally {
        closeCardScenario($wire);
    }
});
