<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\SourceMethod;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\PlatformAdapterRegistry;
use Tests\Support\Doubles\Crawler\FakeAvailableSourceMethod;
use Tests\Support\Doubles\Crawler\FakeCrawlerPlatformForm;
use Tests\Support\Doubles\Crawler\FirstPlatformFilter;
use Tests\Support\Doubles\Crawler\SecondPlatformFilter;

function extensionRegistry(string $platformCode): Registry
{
    $platform = Platform::factory()->create(['code' => $platformCode]);

    return Registry::factory()->for($platform)->create(['code' => 'shared']);
}

test('две площадки с совпадающим registry code получают независимые capability', function (): void {
    $registry = new PlatformAdapterRegistry(platforms: [
        'alpha-contract' => ['source_methods' => [SourceMethod::Csv->value => FakeAvailableSourceMethod::class], 'registry_filters' => ['shared' => ['filter' => FirstPlatformFilter::class, 'form' => FakeCrawlerPlatformForm::class]]],
        'beta-contract' => ['source_methods' => [SourceMethod::Csv->value => FakeAvailableSourceMethod::class], 'registry_filters' => ['shared' => ['filter' => SecondPlatformFilter::class, 'form' => FakeCrawlerPlatformForm::class]]],
    ]);
    $alpha = extensionRegistry(platformCode: 'alpha-contract');
    $beta = extensionRegistry(platformCode: 'beta-contract');

    expect($registry->filterClass(registry: $alpha))->toBe(FirstPlatformFilter::class)
        ->and($registry->filterClass(registry: $beta))->toBe(SecondPlatformFilter::class)
        ->and($registry->sourceMethod(registry: $alpha, method: SourceMethod::Csv))->toBe(FakeAvailableSourceMethod::class);
});

test('удалённая capability завершается explicit fail-closed ошибкой', function (): void {
    $registry = new PlatformAdapterRegistry(platforms: ['alpha-contract' => ['source_methods' => [], 'registry_filters' => []]]);

    expect(fn (): string => $registry->filterClass(registry: extensionRegistry(platformCode: 'alpha-contract')))
        ->toThrow(PlatformAdapterUnavailableException::class, 'shared');
});
