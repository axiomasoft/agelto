<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Enums\Procurement\Proxy\ProxyRouteSource;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\ProxyTarget;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Settings\Procurement\CrawlerProxySettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Platforms\Eis\EisCsvFixture;

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

test('ретрай на 500 забирает второй ответ фикстуры', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::sequence()
            ->push(body: 'Internal Server Error', status: 500)
            ->push(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4);

    Http::assertSentCount(2);
});

test('пустой ответ 200 повторяется и итог собирается из второго ответа', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::sequence()
            ->push(body: '', status: 200)
            ->push(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4);

    Http::assertSentCount(2);
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

test('собранный ListingBatch несёт id узла, который резолвер выдал под скоуп crawl_listing', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->proxyId)->toBe($proxy->id)
        ->and($batch->proxySource)->toBe(ProxyRouteSource::Profile);
});

test('default-профиль выдаёт сохранённый listing-узел и typed source', function (): void {
    Sleep::fake();
    $proxy = activeListingProxy();
    $settings = new CrawlerProxySettings;
    $settings->defaultProxyId = $proxy->id;
    $settings->save();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([$endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200)]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->create(['filter' => ['okpd2Codes' => ['43']], 'proxy_mode' => CrawlerProxyMode::Default]);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));

    expect($batch->proxyId)->toBe($proxy->id)
        ->and($batch->proxySource)->toBe(ProxyRouteSource::Default);
});

test('закрытый breaker у привязанного прокси не переводит listing на direct и запрос не уходит', function () {
    Sleep::fake();
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create([
        'open_until' => now()->addMinute(),
    ]);
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    expect(fn () => app(CsvSourceMethod::class)->collectListing($crawler, $cursor))
        ->toThrow(NoProxyAvailableException::class);

    Http::assertNothingSent();
});

test('профиль с proxy_country=RU при пуле из RU и DE получает только RU-узел', function () {
    Sleep::fake();
    $ruProxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
    Proxy::factory()->country('DE')->withScopes([ProxyScope::CrawlListing])->create();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($ruProxy)->create(['filter' => ['okpd2Codes' => ['43']], 'proxy_country' => 'RU']);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->proxyId)->toBe($ruProxy->id);
});

test('direct-профиль получает typed direct lease от резолвера', function () {
    Sleep::fake();
    Log::spy();
    // Пул кандидатов пуст, но direct остаётся typed требованием: только `ProxyResolver` создаёт
    // direct-лизу и пишет наблюдаемый источник маршрута (D29).
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->create(['filter' => ['okpd2Codes' => ['43']], 'proxy_mode' => CrawlerProxyMode::Direct]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->proxyId)->toBeNull()
        ->and($batch->proxySource?->value)->toBe('direct');

    Http::assertSentCount(1);
    Log::shouldHaveReceived('info')
        ->with('Резолвер прокси: прямой маршрут', Mockery::on(
            static fn (array $context): bool => $context['scope'] === ProxyScope::CrawlListing->value,
        ))
        ->once();
});

test('detail fetch сохраняет source default в partitioned evidence', function (): void {
    Sleep::fake();
    $proxy = Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create();
    $settings = new CrawlerProxySettings;
    $settings->defaultProxyId = $proxy->id;
    $settings->save();
    $registry = Registry::factory()->eis()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
            'card_url_templates' => [
                'ea20' => ['common-info' => 'https://zakupki.gov.ru/common-info.html?regNumber={regNumber}'],
            ],
        ],
    ]);
    Crawler::factory()->for($registry)->create(['proxy_mode' => CrawlerProxyMode::Default]);
    Procurement::factory()->for($registry)->create([
        'registry_number' => '0338200009125000001',
        'procedure_type_raw' => 'Электронный аукцион',
    ]);
    config(['crawler.eis.card_tabs' => ['common-info']]);
    Http::fake(['*common-info.html*' => Http::response('0338200009125000001', 200)]);

    app(CsvSourceMethod::class)->collectDetails(registryNumber: '0338200009125000001', registry: $registry);

    expect(Fetch::query()->where('stage', CrawlStage::Detail->value)->sole()->proxy_source)
        ->toBe(ProxyRouteSource::Default);
});

/**
 * Д-3: `requestWithOutcome()` обязан замерять длительность вокруг HTTP-вызова и передавать её в
 * `ProxyOutcome::success()` — без замера `prc_proxy_targets.latency_ms_avg` остаётся `NULL`
 * даже после успешных запросов, и вес `w_latency` резолвера структурно не влияет ни на что.
 */
test('успешный запрос листинга пишет latency_ms_avg узла (не NULL)', function () {
    Sleep::fake();
    $proxy = activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    $target = ProxyTarget::query()->where('proxy_id', $proxy->id)->where('target', 'zakupki.gov.ru')->firstOrFail();

    expect($target->latency_ms_avg)->not->toBeNull();
});

test('запрос листинга несёт verify из карты crawler.tls.bundles для хоста площадки', function () {
    Sleep::fake();
    $proxy = activeListingProxy();

    $capturedOptions = null;
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions = $options;

        return Http::response(body: EisCsvFixture::body(), status: 200);
    });

    $registry = registryWithOkpd2Scope();
    $crawler = Crawler::factory()->for($registry)->withProxies($proxy)->create(['filter' => ['okpd2Codes' => ['43']]]);
    $cursor = new ListingCursor(blockFrom: 1);

    app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($capturedOptions['verify'] ?? null)->toBe(config('crawler.tls.bundles')['zakupki.gov.ru']);
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
    config(['crawler.'.$limit => $value]);
    Http::fake(['*' => fn () => Http::response(EisCsvFixture::body(), 200)]);
    $run = app(FreshRunProcessor::class)->run($crawler);
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->cursor->get('blockFrom'))->toBe(1)
        ->and(Procurement::query()->count())->toBe(0)
        ->and(SourceIncident::query()->whereNotNull('failure_kind')->firstOrFail()->failure_kind)
        ->toBe(SourceFailureKind::InvalidContent);
})->with([['response_max_bytes', 64], ['csv_max_records', 1], ['csv_max_field_bytes', 8]]);
