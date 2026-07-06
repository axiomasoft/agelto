<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Models\Procurement\Crawler;
use App\Models\Procurement\Registry;
use App\Services\Procurement\Eis\CsvSourceMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\EisCsvFixture;

test('collectListing конвертирует win1251-тело в UTF-8 и строит ListingBatch по фикстуре', function () {
    Sleep::fake();
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

test('при отсутствии активных прокси в пуле делает прямой запрос и логирует предупреждение', function () {
    Sleep::fake();
    Log::spy();
    $endpoint = config('crawler.eis.csv_endpoint');
    Http::fake([
        $endpoint.'*' => Http::response(body: EisCsvFixture::body(), status: 200),
    ]);

    $registry = Registry::factory()->create(['code' => 'fz44']);
    $crawler = Crawler::factory()->for($registry)->create(['filter' => null]);
    $cursor = new ListingCursor(blockFrom: 1);

    $batch = app(CsvSourceMethod::class)->collectListing($crawler, $cursor);

    expect($batch->httpStatus)->toBe(200)
        ->and($batch->rowsTotal)->toBe(4);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context = []) => $context['crawler_id'] === $crawler->id,
    );
});

test('isAvailable возвращает true без сетевых запросов', function () {
    Http::fake();

    $available = app(CsvSourceMethod::class)->isAvailable();

    expect($available)->toBeTrue();
    Http::assertNothingSent();
});
