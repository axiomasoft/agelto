<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\SourceMethod;
use App\Exceptions\Procurement\Crawler\PlatformAdapterConfigurationException;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\PlatformAdapterRegistry;
use Tests\Support\Doubles\Crawler\FakeAvailableSourceMethod;
use Tests\Support\Doubles\Crawler\FakeCrawlerPlatformForm;
use Tests\Support\Doubles\Crawler\FakeUnavailableSourceMethod;
use Tests\Support\Doubles\Crawler\FirstPlatformFilter;
use Tests\Support\Doubles\Crawler\SecondPlatformFilter;

function adapterRegistryWithPlatform(string $platformCode, string $registryCode): Registry
{
    $platform = new Platform;
    $platform->code = $platformCode;

    $registry = new Registry;
    $registry->code = $registryCode;
    $registry->setRelation('platform', $platform);

    return $registry;
}

function syntheticPlatformMap(): array
{
    return [
        'alpha' => [
            'source_methods' => [SourceMethod::Csv->value => FakeAvailableSourceMethod::class],
            'registry_filters' => ['main' => [
                'filter' => FirstPlatformFilter::class,
                'form' => FakeCrawlerPlatformForm::class,
            ]],
        ],
        'beta' => [
            'source_methods' => [SourceMethod::Csv->value => FakeUnavailableSourceMethod::class],
            'registry_filters' => ['main' => [
                'filter' => SecondPlatformFilter::class,
                'form' => FakeCrawlerPlatformForm::class,
            ]],
        ],
    ];
}

test('одинаковый код реестра на разных площадках резолвит разные typed capabilities', function (): void {
    $registry = new PlatformAdapterRegistry(platforms: syntheticPlatformMap());

    expect($registry->filterClass(registry: adapterRegistryWithPlatform(platformCode: 'alpha', registryCode: 'main')))
        ->toBe(FirstPlatformFilter::class)
        ->and($registry->filterClass(registry: adapterRegistryWithPlatform(platformCode: 'beta', registryCode: 'main')))
        ->toBe(SecondPlatformFilter::class)
        ->and($registry->sourceMethod(registry: adapterRegistryWithPlatform(platformCode: 'alpha', registryCode: 'main'), method: SourceMethod::Csv))
        ->toBe(FakeAvailableSourceMethod::class)
        ->and($registry->sourceMethod(registry: adapterRegistryWithPlatform(platformCode: 'beta', registryCode: 'main'), method: SourceMethod::Csv))
        ->toBe(FakeUnavailableSourceMethod::class);
});

test('отсутствующая площадка, метод или filter fail-closed с exact identity', function (): void {
    $registry = new PlatformAdapterRegistry(platforms: syntheticPlatformMap());

    expect(fn () => $registry->filterClass(registry: adapterRegistryWithPlatform(platformCode: 'unknown', registryCode: 'main')))
        ->toThrow(PlatformAdapterUnavailableException::class, 'unknown');
    expect(fn () => $registry->sourceMethod(registry: adapterRegistryWithPlatform(platformCode: 'alpha', registryCode: 'main'), method: SourceMethod::Html))
        ->toThrow(PlatformAdapterUnavailableException::class, 'alpha');
    expect(fn () => $registry->filterClass(registry: adapterRegistryWithPlatform(platformCode: 'alpha', registryCode: 'missing')))
        ->toThrow(PlatformAdapterUnavailableException::class, 'missing');
});

test('невалидные class-string maps отклоняются до runtime resolution', function (): void {
    expect(fn () => new PlatformAdapterRegistry(platforms: [
        'alpha' => [
            'source_methods' => [SourceMethod::Csv->value => stdClass::class],
            'registry_filters' => [],
        ],
    ]))->toThrow(PlatformAdapterConfigurationException::class);
});
