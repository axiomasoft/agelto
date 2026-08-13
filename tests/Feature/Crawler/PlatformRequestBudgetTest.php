<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\PlatformRequestBudget;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param array<string, mixed> $overrides */
function platformWithBudget(string $host, array $overrides = []): Platform
{
    return Platform::factory()->create([
        'base_url' => "https://{$host}/",
        'throttle' => array_replace_recursive([
            'requestsPerMinuteBase' => 1,
            'requestsPerMinutePerProxy' => 0,
            'maxWaitSeconds' => 60,
            'stages' => [
                'listing' => ['minPauseMs' => 0, 'maxPauseMs' => 0],
                'detail' => ['minPauseMs' => 0, 'maxPauseMs' => 0],
                'document' => ['minPauseMs' => 0, 'maxPauseMs' => 0],
            ],
        ], $overrides),
    ]);
}

test('листинг и документы одного хоста делят окно, второй потребитель ждёт слот', function (): void {
    Carbon::setTestNow('2026-08-08 12:00:00');
    Sleep::fake(syncWithCarbon: true);
    platformWithBudget(host: 'eis.test');

    $budget = app(PlatformRequestBudget::class);

    expect($budget->acquire(host: 'https://EIS.TEST/listing', stage: CrawlStage::Listing))->toBe(0)
        ->and($budget->acquire(host: 'eis.test.', stage: CrawlStage::Document))->toBe(60_000);

    Sleep::assertSlept(
        static fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 60,
        times: 1,
    );
});

test('ёмкость растёт только от активных привязанных узлов площадки', function (): void {
    Sleep::fake();
    $platform = platformWithBudget(host: 'eis.test', overrides: [
        'requestsPerMinutePerProxy' => 1,
        'maxWaitSeconds' => 0,
    ]);
    $platform->update(['code' => 'eis']);
    $registry = Registry::factory()->for($platform)->create();
    $active = Proxy::factory()->create();
    $inactive = Proxy::factory()->create(['is_active' => false]);
    Crawler::factory()->for($registry)->withProxies($active, $inactive)->create();

    $budget = app(PlatformRequestBudget::class);

    expect($budget->acquire(host: 'eis.test', stage: CrawlStage::Listing))->toBe(0)
        ->and($budget->acquire(host: 'eis.test', stage: CrawlStage::Detail))->toBe(0);

    expect(fn (): int => $budget->acquire(host: 'eis.test', stage: CrawlStage::Document))
        ->toThrow(PlatformBudgetExhaustedException::class);
});

test('стадийная пауза берётся из резолвера площадки и входит в измеренное ожидание', function (): void {
    Sleep::fake();
    platformWithBudget(host: 'eis.test', overrides: [
        'requestsPerMinuteBase' => 10,
        'stages' => [
            'listing' => ['minPauseMs' => 321, 'maxPauseMs' => 321],
        ],
    ]);

    $waitedMs = app(PlatformRequestBudget::class)->acquire(
        host: 'eis.test',
        stage: CrawlStage::Listing,
    );

    expect($waitedMs)->toBe(321);
    Sleep::assertSlept(
        static fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 321,
        times: 1,
    );
});

test('разные хосты не расходуют общий счётчик', function (): void {
    Sleep::fake();
    platformWithBudget(host: 'eis.test');
    platformWithBudget(host: 'example.test');

    $budget = app(PlatformRequestBudget::class);

    expect($budget->acquire(host: 'eis.test', stage: CrawlStage::Listing))->toBe(0)
        ->and($budget->acquire(host: 'example.test', stage: CrawlStage::Listing))->toBe(0);

    Sleep::assertNeverSlept();
});
