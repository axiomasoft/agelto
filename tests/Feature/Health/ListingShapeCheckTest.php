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
    $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
    $run = Run::factory()->create(['crawler_id' => $crawler->id]);

    return Fetch::factory()->create([
        'crawler_run_id' => $run->id,
        'stage' => CrawlStage::Listing->value,
        'fetched_at' => now(),
        ...$attributes,
    ]);
}

test('проверка формы листинга подтверждает блок со строками', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingFetch($platform, ['rows_total' => 2, 'rows_new' => 1, 'rows_updated' => 1, 'http_status' => 200]);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok);
});

test('проверка формы листинга ловит непустой блок без записанных строк', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    listingFetch($platform, ['rows_total' => 2, 'rows_new' => 0, 'rows_updated' => 0, 'http_status' => 200]);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken);
});

test('проверка формы листинга не называет отсутствие блоков здоровьем', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);

    $result = app(ListingShapeCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData);
});
