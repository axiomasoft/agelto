<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Health\Checks\ListingShapeCheck;
use App\Services\Procurement\Health\HealthBaselineReader;

function listingFetch(Platform $platform, array $attributes = []): Fetch
{
    $registry = Registry::query()
        ->where('platform_id', $platform->id)
        ->where('code', 'fz44')
        ->first();

    if ($registry === null) {
        $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    }

    $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
    $run = Run::factory()->create(['crawler_id' => $crawler->id]);

    return Fetch::factory()->create([
        'crawler_run_id' => $run->id,
        'stage' => CrawlStage::Listing->value,
        'fetched_at' => now(),
        ...$attributes,
    ]);
}

test('проверка формы листинга подтверждает неизменившийся распарсенный блок', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingFetch($platform, ['rows_total' => 2, 'rows_new' => 0, 'rows_updated' => 0, 'rows_parsed' => 2, 'rows_errors' => 0, 'http_status' => 200]);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok);
});

test('проверка формы листинга считает полностью ошибочный блок broken среди healthy samples', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingFetch($platform, ['rows_total' => 10, 'rows_parsed' => 10, 'rows_errors' => 0, 'http_status' => 200]);
    listingFetch($platform, ['rows_total' => 2, 'rows_parsed' => 0, 'rows_errors' => 2, 'http_status' => 200]);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken);
});

test('проверка формы листинга отражает частичные ошибки по доле строк', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingFetch($platform, ['rows_total' => 10, 'rows_parsed' => 9, 'rows_errors' => 1, 'http_status' => 200]);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Degraded)
        ->and($result->measured['max_row_error_share'])->toBe(0.1)
        ->and($result->measured['row_error_share_denominator'])->toBe('rows_total');
});

test('проверка формы листинга считает исторические null-метрики неизвестными', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingFetch($platform, ['rows_total' => 2, 'rows_parsed' => null, 'rows_errors' => null, 'http_status' => 200]);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
        ->and($result->measured['unknown_parse_metrics'])->toBe(1);
});

test('проверка формы листинга не называет отсутствие блоков здоровьем', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData);
});
