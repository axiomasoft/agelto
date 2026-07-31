<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Enums\Procurement\ProxyScope;
use App\Exceptions\Procurement\NoProxyAvailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Eis\CsvSourceMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\EisCsvFixture;

/**
 * Активный узел скоупа `crawl_listing` — резолвер (research/02 §9.1) не выдаёт лизу без
 * кандидата, поэтому тесты, не проверяющие само отсутствие прокси, заводят узел явно.
 */
function activeListingProxy(): Proxy
{
    return Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create();
}

test('collectListing конвертирует win1251-тело в UTF-8 и строит ListingBatch по фикстуре', function () {
    Sleep::fake();
    activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(
            body: EisCsvFixture::body(),
            status: 200,
            headers: ['Content-Type' => 'application/download; charset=windows-1251'],
        ),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch)->toBeInstanceOf(ListingBatch::class)
        ->and($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4)
        ->and($batch->rows)->toHaveCount(4);

    $firstRow = $batch->rows[0];

    expect($firstRow['Реестровый номер закупки'])->toBe('0173200001234000001')
        ->and($firstRow['Регион'])->toBe('Москва')
        ->and(mb_check_encoding($firstRow['Регион'], 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding($batch->rawBody, 'UTF-8'))->toBeTrue();

    // Поле в фикстуре не обёрнуто внешними кавычками — `str_getcsv` разэкранирует `""` только
    // внутри quoted-поля, поэтому текст с внутренними `""` остаётся как есть (корректное
    // поведение CSV-парсера, не баг).
    expect($firstRow['Предмет закупки'])->toBe('Поставка ""офисной"" мебели для нужд учреждения');
});

test('dedupSignature вычисляется по колонке «Реестровый номер закупки» первой строки блока', function () {
    Sleep::fake();
    activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(
            body: EisCsvFixture::body(),
            status: 200,
        ),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->dedupSignature)->toBe('0173200001234000001');
});

test('ретрай на 500 забирает второй ответ фикстуры', function () {
    Sleep::fake();
    activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::sequence()
            ->push(body: 'Internal Server Error', status: 500)
            ->push(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4);

    Http::assertSentCount(2);
});

test('пустой ответ 200 повторяется и итог собирается из второго ответа', function () {
    Sleep::fake();
    activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::sequence()
            ->push(body: '', status: 200)
            ->push(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4);

    Http::assertSentCount(2);
});

test('query-параметры запроса содержат from/to, флаг реестра и сортировку', function () {
    Sleep::fake();
    activeListingProxy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => ['okpd2' => '86.10']]);
    $cursor = new ListingCursor(blockFrom: 501);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->requestParams['from'])->toBe('501')
        ->and($batch->requestParams['to'])->toBe('1000')
        ->and($batch->requestParams['fz44'])->toBe('on')
        ->and($batch->requestParams['sortBy'])->toBe('UPDATE_DATE')
        ->and($batch->requestParams['sortDirection'])->toBe('false')
        ->and($batch->requestParams['registryNumberCsv'])->toBe('on')
        ->and($batch->requestParams['okpd2'])->toBe('86.10');

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

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->proxyId)->toBe($proxy->id);
});

test('без активного прокси скоупа crawl_listing резолвер бросает NoProxyAvailableException и запрос не уходит', function () {
    Sleep::fake();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
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

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null, 'proxy_country' => 'RU']);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->proxyId)->toBe($ruProxy->id);
});

test('профиль с use_proxy=false не вызывает резолвер — запрос уходит напрямую с NULL proxyId', function () {
    Sleep::fake();
    // Пул кандидатов пуст: если бы `resolve()` всё же был вызван, тест упал бы
    // `NoProxyAvailableException` — этого достаточно, чтобы доказать, что `use_proxy = false`
    // обходит резолвер целиком, а не просто получает от него direct-подобный результат (research/02 §10).
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null, 'use_proxy' => false]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->proxyId)->toBeNull();

    Http::assertSentCount(1);
});

test('isAvailable возвращает true без сетевых запросов', function () {
    Http::fake();

    $available = app(CsvSourceMethod::class)->isAvailable();

    expect($available)->toBeTrue();
    Http::assertNothingSent();
});
