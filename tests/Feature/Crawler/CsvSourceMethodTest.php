<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Crawler\InvalidListingResponse;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

/**
 * Активный узел скоупа `crawl_listing` — резолвер (research/02 §9.1) не выдаёт лизу без
 * кандидата, поэтому тесты, не проверяющие само отсутствие прокси, заводят узел явно.
 */
function activeListingProxy(): Proxy
{
    return Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
}

/**
 * Реестр `fz44` с резолвящимся скоупом ОКПД2 (P2.3): `EisCsvUrlBuilder` отказывает на
 * нерезолвящемся коде, поэтому фикстуре нужен узел карты `prc_okpd2_nodes` под код `43`,
 * которым эти тесты фильтруют профиль.
 */
function registryWithOkpd2Scope(): Registry
{
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);

    return $registry;
}

beforeEach(fn () => LegacyBusinessNetwork::install());

/** Real application client, server-owned responses and canonical explicit routes. */
function csvWireScenario(bool $proxy = false, bool $tls = false): array
{
    PermitFixtures::clean();
    $wire = new WireFixture($tls ? 443 : 0);
    $registry = CallerFixtures::platform($wire);
    if ($tls) {
        config(['crawler.eis.csv_endpoint' => $wire->url('/csv', true)->reveal(),
            'platforms.eis.endpoints.listing.url' => $wire->url('/csv', true)->reveal()]);
        app(SourceCatalogSync::class)->syncRegistered(['eis']);
    }
    Okpd2Node::query()->firstOrCreate(['platform_id' => $registry->platform_id, 'code' => '43'],
        Okpd2Node::factory()->make(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921'])->getAttributes());
    $node = $proxy ? Proxy::factory()->country('RU')->create(['host' => '127.0.0.1', 'port' => $wire->ports['proxy'], 'username' => null]) : null;
    if ($node !== null) {
        app(ProcessTransaction::class)->run(fn () => app(SourceCatalogRepository::class)->initializeRoute($node->route_id, app(OutboundPolicy::class)));
    }
    $factory = Crawler::factory()->for($registry);
    $crawler = ($node === null ? $factory : $factory->withProxies($node))->create();
    CallerFixtures::healthReady();

    return [$wire, $crawler, $node];
}

function closeCsvWireScenario($wire): void
{
    $wire->stop();
    PermitFixtures::clean();
}

test('истёкший deadline листинга запрещает новый HTTP-запрос', function (): void {
    Sleep::fake();
    Http::fake();
    $proxy = activeListingProxy();
    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);

    expect(fn () => app(CsvSourceMethod::class)->collectListingWithinDeadline(
        crawler: $crawler, cursor: new ListingCursor(blockFrom: 1), deadlineAt: microtime(true) - 1,
    ))->toThrow(OutboundRequestDeadlineExceeded::class);

    Http::assertNothingSent();
});

test('collectListing конвертирует win1251-тело в UTF-8 и строит ListingBatch по фикстуре', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(
            body: EisCsvFixture::body(),
            status: 200,
            headers: ['Content-Type' => 'application/download; charset=windows-1251'],
        ),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch)->toBeInstanceOf(ListingBatch::class)
        ->and($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4)
        ->and($batch->rows)->toHaveCount(4);

    $firstRow = $batch->rows[0];

    // `collectListing()` отдаёт СЫРЫЕ строки CSV — префикс «№» перед реестровым номером
    // (живая проба P4.9, `findings/P4-csv-headers.md`) снимает только `EisCsvRowMapper::map()`.
    expect($firstRow['Реестровый номер закупки'])->toBe('№0338300047926000272')
        ->and($firstRow['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)'])->toBe('Электронный аукцион')
        ->and(mb_check_encoding($firstRow['Наименование Заказчика'], 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding($batch->rawBody, 'UTF-8'))->toBeTrue();

    // Поле в фикстуре обёрнуто внешними кавычками — `str_getcsv` корректно разэкранирует `""`
    // в `"` внутри quoted-поля (реальное значение живой пробы).
    expect($firstRow['Наименование Заказчика'])->toBe(
        'ГОСУДАРСТВЕННОЕ БЮДЖЕТНОЕ УЧРЕЖДЕНИЕ ЗДРАВООХРАНЕНИЯ КАМЧАТСКОГО КРАЯ "ЕЛИЗОВСКАЯ РАЙОННАЯ БОЛЬНИЦА"',
    );
});

test('dedupSignature вычисляется по колонке «Реестровый номер закупки» первой строки блока', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(
            body: EisCsvFixture::body(),
            status: 200,
        ),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->dedupSignature)->toBe('№0338300047926000272');
});

test('ретрай на 500 забирает второй ответ фикстуры', function (): void {
    [$wire, $crawler] = csvWireScenario();
    try {
        $wire->respond('/csv', 'Internal Server Error', 500);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $source = app(CsvSourceMethod::class);
        expect(fn () => $source->collectListing($crawler, new ListingCursor(blockFrom: 1)))
            ->toThrow(OutboundUnavailable::class);
        $batch = $source->collectListing($crawler, new ListingCursor(blockFrom: 1));
        expect($batch->httpStatus)->toBe(200)->and($batch->rowsTotal)->toBe(4)
            ->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(2)
            ->and(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('status')->all())->toBe([500, 200]);
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('пустой ответ 200 повторяется и итог собирается из второго ответа', function (): void {
    [$wire, $crawler] = csvWireScenario();
    try {
        $wire->respond('/csv', '', headers: ['Content-Type' => 'text/csv']);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $source = app(CsvSourceMethod::class);
        expect(fn () => $source->collectListing($crawler, new ListingCursor(blockFrom: 1)))
            ->toThrow(InvalidListingResponse::class, 'empty_body');
        expect(DB::table('prc_adapter_health')->where('capability', 'listing')->value('state'))->toBe('suspect');
        $batch = $source->collectListing($crawler, new ListingCursor(blockFrom: 1));
        expect($batch->rowsTotal)->toBe(4)->and($batch->validation->verdict->value)->toBe('accepted')
            ->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table('prc_adapter_health')->where('capability', 'listing')->value('state'))->toBe('healthy');
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('query-параметры запроса содержат from/to, флаг реестра и сортировку', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['stageCompleted' => true, 'okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 501);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->requestParams['from'])->toBe('501')
        ->and($batch->requestParams['to'])->toBe('1000')
        ->and($batch->requestParams['fz44'])->toBe('on')
        ->and($batch->requestParams['sortBy'])->toBe('UPDATE_DATE')
        ->and($batch->requestParams['sortDirection'])->toBe('false')
        ->and($batch->requestParams['registryNumberCsv'])->toBe('on')
        ->and($batch->requestParams['pc'])->toBe('on');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'fz44=on')
            && str_contains((string) $request->url(), 'from=501')
            && str_contains((string) $request->url(), 'to=1000')
            && str_contains((string) $request->url(), 'sortBy=UPDATE_DATE');
    });
});

test('собранный ListingBatch несёт id узла, который резолвер выдал под скоуп crawl_listing', function (): void {
    [$wire, $crawler, $proxy] = csvWireScenario(proxy: true);
    try {
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        $attempt = DB::table('prc_request_attempts')->sole();
        expect($batch->validation->requestIds[0]->value)->toBe($attempt->request_id)
            ->and($attempt->route_id)->toBe($proxy->route_id)->and(array_column($wire->receipts(), 'kind'))->toBe(['connect', 'http'])
            ->and($wire->receipts()[0]['request'])->toStartWith('CONNECT 127.0.0.1:');
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('default-профиль выдаёт сохранённый listing-узел и typed source', function (): void {
    [$wire, $crawler, $proxy] = csvWireScenario(proxy: true);
    try {
        $profile = DB::table('prc_network_route_profiles')->where('profile_key', 'default')->sole();
        DB::table('prc_network_route_profile_members')->where('profile_id', $profile->id)->delete();
        DB::table('prc_network_route_profile_members')->insert(['profile_id' => $profile->id, 'route_id' => $proxy->route_id]);
        $crawler->update(['route_profile_id' => null]);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        expect(DB::table('prc_request_attempts')->sole()->route_id)->toBe($proxy->route_id)
            ->and(json_decode(DB::table('prc_network_permits')->sole()->policy_versions, true)['profile:'.$profile->id])->toBe(1);
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('закрытый breaker у привязанного прокси не переводит listing на direct и запрос не уходит', function (): void {
    [$wire, $crawler, $proxy] = csvWireScenario(proxy: true);
    try {
        DB::table('prc_route_health')->where('route_id', $proxy->route_id)->update([
            'state' => 'open', 'open_until' => DB::raw("clock_timestamp() + interval '1 minute'")]);
        expect(fn () => app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1)))
            ->toThrow(OutboundUnavailable::class);
        expect($wire->receipts())->toBe([])->and(DB::table('prc_request_attempts')->count())->toBe(0);
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('профиль с proxy_country=RU при пуле из RU и DE получает только RU-узел', function (): void {
    [$wire, $crawler, $ru] = csvWireScenario(proxy: true);
    try {
        $de = Proxy::factory()->country('DE')->create(['host' => '127.0.0.1', 'port' => $wire->ports['stall_proxy']]);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        expect(DB::table('prc_request_attempts')->sole()->route_id)->toBe($ru->route_id)
            ->and(DB::table('prc_network_route_profile_members')->where('profile_id', $crawler->route_profile_id)->pluck('route_id')->all())->toBe([$ru->route_id])
            ->and(DB::table('prc_request_attempts')->where('route_id', $de->route_id)->count())->toBe(0);
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('direct-профиль получает typed direct lease от резолвера', function (): void {
    [$wire, $crawler] = csvWireScenario();
    try {
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        $attempt = DB::table('prc_request_attempts')->sole();
        expect($batch->httpStatus)->toBe(200)->and($wire->receipts())->toHaveCount(1)
            ->and($wire->receipts()[0]['path'])->toStartWith('/csv')
            ->and(DB::table('prc_network_routes')->where('id', $attempt->route_id)->sole()->kind)->toBe('direct');
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('detail fetch сохраняет source default в partitioned evidence', function (): void {
    [$wire, $crawler] = csvWireScenario();
    try {
        $registry = $crawler->registry;
        config(['crawler.eis.card_tabs' => ['common-info']]);
        $registry->update(['settings' => ['procedure_url_types' => ['Электронный аукцион' => 'ea20'],
            'card_url_templates' => ['ea20' => ['common-info' => $wire->url('/common-info?regNumber={regNumber}')->reveal()]]]]);
        $procurement = Procurement::factory()->for($registry)->create(['source_identity_value' => '0338200009125000001', 'procedure_type_raw' => 'Электронный аукцион']);
        $wire->respond('/common-info', CardHtmlFixture::commonInfo(), headers: ['Content-Type' => 'text/html; charset=UTF-8']);
        $details = app(CsvSourceMethod::class)->collectDetails($procurement->source_identity_value, $registry);
        $attempt = DB::table('prc_request_attempts')->sole();
        expect($details->validation->requestIds[0]->value)->toBe($attempt->request_id)
            ->and(DB::table('prc_source_targets')->where('id', $attempt->target_id)->sole()->capability)->toBe('detail')
            ->and(json_decode(DB::table('prc_network_permits')->sole()->policy_versions, true)['profile:'.DB::table('prc_network_route_profiles')->where('profile_key', 'default')->sole()->id])->toBe(1)
            ->and(Fetch::query()->count())->toBe(0);
    } finally {
        closeCsvWireScenario($wire);
    }
});

/**
 * Д-3: `requestWithOutcome()` обязан замерять длительность вокруг HTTP-вызова и передавать её в
 * `ProxyOutcome::success()` — без замера `prc_proxy_targets.latency_ms_avg` остаётся `NULL`
 * даже после успешных запросов, и вес `w_latency` резолвера структурно не влияет ни на что.
 */
test('успешный запрос листинга пишет latency_ms_avg узла (не NULL)', function (): void {
    [$wire, $crawler] = csvWireScenario();
    try {
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        $attempt = DB::table('prc_request_attempts')->sole();
        expect($attempt->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($attempt->finished_at)->not->toBeNull()->and($attempt->status)->toBe(200);
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('запрос листинга несёт verify из карты crawler.tls.bundles для хоста площадки', function (): void {
    [$wire, $crawler] = csvWireScenario(tls: true);
    try {
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        expect(app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1))->rowsTotal)->toBe(4);
        config(['outbound.http.tls_bundles' => []]);
        expect(fn () => app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1)))
            ->toThrow(OutboundUnavailable::class);
        expect($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('outcome')->all())->toBe(['response', 'tls']);
    } finally {
        closeCsvWireScenario($wire);
    }
});

test('isAvailable возвращает true без сетевых запросов', function () {
    Http::fake();

    $available = app(CsvSourceMethod::class)->isAvailable();

    expect($available)->toBeTrue();
    Http::assertNothingSent();
});

/**
 * Win-1251 CSV-блок из заголовка и произвольных записей (поля экранируются по RFC 4180).
 *
 * @param  list<list<string>>  $records
 */
function win1251Csv(array $records): string
{
    $header = ['Реестровый номер закупки', 'Наименование закупки', 'Дата обновления', 'Дата размещения'];
    $records = array_map(static fn (array $record): array => [...$record, '01.10.2026'], $records);
    $line = static fn (array $fields): string => implode(';', array_map(
        static fn (string $field): string => '"'.str_replace('"', '""', $field).'"',
        $fields,
    ));

    $body = implode("\r\n", array_map($line, [$header, ...$records]))."\r\n";

    return mb_convert_encoding($body, 'Windows-1251', 'UTF-8');
}

test('поле в кавычках с переводом строки остаётся одной записью листинга', function (): void {
    Sleep::fake();
    $proxy = activeListingProxy();
    Http::fake([
        config('crawler.eis.csv_endpoint').'*' => Http::response(body: win1251Csv([
            ['№0001', "Ремонт кровли\r\nи фасада", '01.10.2026'],
            ['№0002', 'Поставка бумаги', '01.10.2026'],
        ])),
    ]);
    $crawler = Crawler::factory()->for(registryWithOkpd2Scope())->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));

    expect($batch->rowsTotal)->toBe(2)
        ->and($batch->rowsMalformed)->toBe(0)
        ->and($batch->rows)->toHaveCount(2)
        ->and($batch->rows[0]['Наименование закупки'])->toBe("Ремонт кровли\r\nи фасада")
        ->and($batch->rows[1]['Реестровый номер закупки'])->toBe('№0002');
});

test('обратный слэш в конце поля не склеивает соседние поля', function (): void {
    Sleep::fake();
    $proxy = activeListingProxy();
    Http::fake([
        config('crawler.eis.csv_endpoint').'*' => Http::response(body: win1251Csv([
            ['№0001', 'Каталог C:\\', '01.10.2026'],
        ])),
    ]);
    $crawler = Crawler::factory()->for(registryWithOkpd2Scope())->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));

    expect($batch->rows)->toHaveCount(1)
        ->and($batch->rows[0]['Наименование закупки'])->toBe('Каталог C:\\')
        ->and($batch->rows[0]['Дата обновления'])->toBe('01.10.2026');
});

test('битая запись учитывается в rowsTotal, чтобы неполный блок не считался последним', function (): void {
    Sleep::fake();
    Log::spy();
    $proxy = activeListingProxy();
    $body = win1251Csv([['№0001', 'Поставка', '01.10.2026']])
        .mb_convert_encoding("\"№0002\";\"только два поля\"\r\n", 'Windows-1251', 'UTF-8');
    Http::fake([config('crawler.eis.csv_endpoint').'*' => Http::response(body: $body)]);
    $crawler = Crawler::factory()->for(registryWithOkpd2Scope())->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));

    expect($batch->rowsTotal)->toBe(2)
        ->and($batch->rowsMalformed)->toBe(1)
        ->and($batch->rows)->toHaveCount(1);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'CSV-записи') && $context['malformed'] === 1,
    )->once();
});

test('лимиты bytes records и field останавливают fresh без продвижения курсора', function (string $limit, int $value): void {
    Sleep::fake();
    Storage::fake('s3');
    $proxy = activeListingProxy();
    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    if ($limit === 'response_max_bytes') {
        [$wire, $crawler] = csvWireScenario();
        try {
            $catalog = (new EisDefinition(config()))->definition()->sourceTargets;
            $targets = [];
            foreach ($catalog->targets as $target) {
                if ($target->capability === 'listing') {
                    $profile = array_values($target->profiles)[0];
                    $limited = RequestProfile::fromArray(array_replace($profile->toArray(), ['max_entity_bytes' => $value]));
                    $target = new SourceTargetDefinition($target->key, $target->capability,
                        $target->origin, [$limited], adapterContract: $target->adapterContract);
                }
                $targets[] = $target;
            }
            app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions('eis', $targets));
            $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
            $run = app(FreshRunProcessor::class)->run($crawler);
            expect($run->status)->toBe(RunStatus::Paused)->and($run->cursor->get('blockFrom'))->toBe(1)
                ->and(DB::table('prc_request_attempts')->sole()->outcome)->toBe('byte_limit')
                ->and(Procurement::query()->count())->toBe(0);
        } finally {
            closeCsvWireScenario($wire);
        }

        return;
    }
    config(['crawler.'.$limit => $value]);
    Http::fake(['*' => fn () => Http::response(EisCsvFixture::body(), 200)]);
    $run = app(FreshRunProcessor::class)->run($crawler);
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->cursor->get('blockFrom'))->toBe(1)
        ->and(Procurement::query()->count())->toBe(0)
        ->and(SourceIncident::query()->whereNotNull('failure_kind')->firstOrFail()->failure_kind)
        ->toBe(SourceFailureKind::InvalidContent);
})->with([['response_max_bytes', 64], ['csv_max_records', 1], ['csv_max_field_bytes', 8]]);
