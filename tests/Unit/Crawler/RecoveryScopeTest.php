<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Models\Procurement\Platform\Registry;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('сохраняет предметный фильтр fresh-профиля в cursor и накладывает его на archive runtime', function () {
    // The canonical EIS registry may already have been created by another committed fixture.
    $existingRegistry = EisCrawlerFactory::new()->make()->registry;
    $registry = EisCrawlerFactory::new()->make()->registry;
    expect($registry)->toBeInstanceOf(Registry::class)
        ->and($registry->getKey())->toBe($existingRegistry->getKey());
    $freshCrawler = EisCrawlerFactory::new()
        ->filter(new EisSearchFilter(
            stageApplications: true,
            okpd2Codes: ['43'],
            priceFrom: 100,
            priceTo: 1_000,
        ))
        ->create(['registry_id' => $registry->id, 'listing_kind' => AcquisitionKind::Fresh]);
    $archiveCrawler = EisCrawlerFactory::new()
        ->filter(new EisSearchFilter(stageCompleted: true, okpd2Codes: ['99'], priceTo: 5_000))
        ->create(['registry_id' => $registry->id, 'listing_kind' => AcquisitionKind::Archive]);

    $scope = RecoveryScope::fromFreshCrawler($freshCrawler);
    $cursor = ListingCursor::fromArray((new ListingCursor(recoveryScope: $scope->toArray()))->toArray());
    $executionCrawler = RecoveryScope::fromArray($cursor->recoveryScope ?? [])->applyTo($archiveCrawler);

    expect($executionCrawler->id)->toBe($archiveCrawler->id)
        ->and($executionCrawler->filter)->toBeInstanceOf(EisSearchFilter::class)
        ->and($executionCrawler->filter->stageApplications)->toBeTrue()
        ->and($executionCrawler->filter->stageCompleted)->toBeFalse()
        ->and($executionCrawler->filter->okpd2Codes)->toBe(['43'])
        ->and($executionCrawler->filter->priceFrom)->toBe(100)
        ->and($executionCrawler->filter->priceTo)->toBe(1_000);
});
