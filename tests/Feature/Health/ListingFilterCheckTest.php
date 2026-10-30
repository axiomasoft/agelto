<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\Diagnostics\ListingFilterCheck;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

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

beforeEach(fn () => LegacyBusinessNetwork::install());

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

test('проверка считает пустой ответ недостатком данных', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake([
        '*' => Http::sequence()
            ->push(body: EisCsvFixture::body(), status: 200)
            ->push(body: '', status: 200),
    ]);

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(2);

    Http::assertSentCount(2);
});

test('проверка называет исчерпание бюджета недостатком данных', function (): void {
    [$platform] = listingFilterProfile();
    diagnosticListingUnavailable(NetworkDeferralReason::RateLimit);
    Http::fake();

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(0)
        ->and($result->message)->toContain('rate_limit');

    Http::assertNothingSent();
});

test('проверка называет недоступность прокси недостатком данных', function (): void {
    [$platform] = listingFilterProfile(withProxy: true);
    diagnosticListingUnavailable(NetworkDeferralReason::NoRoute);
    Http::fake();

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(0)
        ->and($result->message)->toContain('no_route');

    Http::assertNothingSent();
});

test('проверка называет сетевой отказ недостатком данных', function (): void {
    [$platform] = listingFilterProfile();
    Http::fake(fn (): never => throw new ConnectionException('Network is unavailable'));

    $result = runListingFilterCheck($platform);

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->requestsUsed)->toBe(1)
        ->and($result->message)->toContain('не получила проверенный CSV');
});

test('проверка не повторяет ответ площадки и не делает третий запрос', function (): void {
    PermitFixtures::clean();
    $wire = new WireFixture;
    try {
        $registry = CallerFixtures::platform($wire);
        Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);
        Crawler::factory()->for($registry)->create(['filter' => new EisSearchFilter(okpd2Codes: ['43'])]);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $wire->respond('/csv', 'Service unavailable', 503);
        $result = runListingFilterCheck($registry->platform);
        expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
            ->and($result->requestsUsed)->toBe(2)
            ->and($wire->receipts())->toHaveCount(2)
            ->and(DB::table('prc_request_attempts')->count())->toBe(2);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }
});

/** A typed admission result at the explicit logical-call seam; no physical-network claim. */
function diagnosticListingUnavailable(NetworkDeferralReason $reason): void
{
    $network = Mockery::mock(LegacyOutbound::class);
    $network->shouldReceive('validated')->once()->andThrow(new OutboundUnavailable(
        new TransportDeferred($reason,
            new RetryAt(new DateTimeImmutable('+1 minute')))));
    app()->instance(LegacyOutbound::class, $network);
}
