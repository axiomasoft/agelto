<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Health\CheckResult;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Health\Checks\ListingFilterCheck;
use App\Services\Procurement\Health\HealthBaselineReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\EisCsvFixture;

beforeEach(function (): void {
    Sleep::fake();
    $host = (string) parse_url((string) config('crawler.eis.csv_endpoint'), PHP_URL_HOST);
    RateLimiter::clear("crawl:budget:{$host}");
});

function listingFilterProfile(bool $withProxy = false): array
{
    $platform = Platform::factory()->create(['code' => 'eis']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $platform->id, 'code' => '43', 'external_id' => '8873921']);

    if (! $withProxy) {
        return [$platform, Crawler::factory()->for($registry)->create([
            'filter' => new EisSearchFilter(okpd2Codes: ['43']),
        ])];
    }

    $proxy = Proxy::factory()
        ->country('RU')
        ->withScopes([ProxyScope::CrawlListing])
        ->open()
        ->create();

    return [$platform, Crawler::factory()->for($registry)->withProxies($proxy)->create([
        'filter' => new EisSearchFilter(okpd2Codes: ['43']),
    ])];
}

/** @param list<string> $numbers */
function listingFilterBodyWithNumbers(array $numbers): string
{
    return str_replace(
        ['0338300047926000272', '0361300027526000097', '0122300016126000008', '0120300012726000244'],
        $numbers,
        EisCsvFixture::body(),
    );
}

/** @return array<string, string> */
function listingFilterQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function runListingFilterCheck(Platform $platform): CheckResult
{
    $to = CarbonImmutable::parse('2026-08-08 12:00:00', 'UTC');

    return app(ListingFilterCheck::class)->run(
        platform: $platform,
        baseline: app(HealthBaselineReader::class)->read(),
        from: $to->subDay(),
        to: $to,
    );
}

test('проверка подтверждает действие ОКПД2 и отправляет два запроса боевым путём', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake([
        '*' => Http::sequence()
            ->push(body: EisCsvFixture::body(), status: 200)
            ->push(body: listingFilterBodyWithNumbers([
                '1111111111111111111',
                '2222222222222222222',
                '3333333333333333333',
                '4444444444444444444',
            ]), status: 200),
    ]);

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok)
        ->and($result->requestsUsed)->toBe(2)
        ->and($result->measured['overlap'])->toBe(0)
        ->and($result->measured['overlap_share'])->toBe(0.0);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => ! array_key_exists('okpd2Ids', listingFilterQuery($request))
        && listingFilterQuery($request)['publishDateFrom'] === '07.08.2026'
        && listingFilterQuery($request)['publishDateTo'] === '07.08.2026'
        && listingFilterQuery($request)['from'] === '1'
        && listingFilterQuery($request)['to'] === '500');
    Http::assertSent(fn (Request $request): bool => (listingFilterQuery($request)['okpd2Ids'] ?? null) === '8873921'
        && (listingFilterQuery($request)['okpd2IdsWithNested'] ?? null) === 'on'
        && listingFilterQuery($request)['publishDateFrom'] === '07.08.2026'
        && listingFilterQuery($request)['publishDateTo'] === '07.08.2026');
});

test('проверка считает совпавшие множества реестровых номеров поломкой фильтра', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake([
        '*' => Http::sequence()
            ->push(body: EisCsvFixture::body(), status: 200)
            ->push(body: EisCsvFixture::body(), status: 200),
    ]);

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken)
        ->and($result->measured['overlap'])->toBe(4)
        ->and($result->measured['overlap_share'])->toBe(1.0);
});

test('проверка считает пустой фильтрованный блок поломкой', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake([
        '*' => Http::sequence()
            ->push(body: EisCsvFixture::body(), status: 200)
            ->push(body: '', status: 200),
    ]);

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken)
        ->and($result->measured['registry_numbers_filtered'])->toBe(0);

    Http::assertSentCount(2);
});

test('проверка называет исчерпание бюджета недостатком данных', function (): void {
    [$platform] = listingFilterProfile();
    config(['crawler.budget.requests_per_minute_base' => 0]);
    Http::fake();

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(1)
        ->and($result->message)->toContain('Бюджет запросов');

    Http::assertNothingSent();
});

test('проверка называет недоступность прокси недостатком данных', function (): void {
    [$platform] = listingFilterProfile(withProxy: true);
    Http::fake();

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(1)
        ->and($result->message)->toContain('Нет доступного прокси');

    Http::assertNothingSent();
});

test('проверка называет сетевой отказ недостатком данных', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake(fn (): never => throw new ConnectionException('Network is unavailable'));

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(1)
        ->and($result->message)->toContain('Network is unavailable');
});

test('проверка не повторяет ответ площадки и не делает третий запрос', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake([
        '*' => Http::sequence()
            ->push(body: EisCsvFixture::body(), status: 200)
            ->push(body: 'Service unavailable', status: 503),
    ]);

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(2);

    Http::assertSentCount(2);
});
