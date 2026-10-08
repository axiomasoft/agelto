<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\SkippedListingRow;
use App\Dto\Procurement\Details\DocumentsSnapshot;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Exceptions\Procurement\Crawler\PlatformAdapterConfigurationException;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\DetailParser;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\PlatformAdapterRegistry;
use Illuminate\Support\Facades\Storage;
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

test('нетипизированные capability maps отклоняются при сборке registry', function (): void {
    expect(fn () => new PlatformAdapterRegistry(platforms: [
        'alpha' => ['source_methods' => 'csv', 'registry_filters' => []],
    ]))->toThrow(PlatformAdapterConfigurationException::class, 'source_methods');
});

test('две несовместимые площадки выбирают собственные mapper parser baseline и размер блока в одном контейнере', function (): void {
    $map = syntheticPlatformMap();
    foreach (['alpha' => 17, 'beta' => 29] as $code => $size) {
        $mapper = Mockery::namedMock('Audit'.ucfirst($code).'Mapper', ListingRowMapper::class);
        $mapper->shouldReceive('map')->with([$code.'_id' => '1'], Mockery::type(Crawler::class))
            ->andReturn(new SkippedListingRow($code));
        $parser = Mockery::namedMock('Audit'.ucfirst($code).'Parser', DetailParser::class);
        $parser->shouldReceive('documents')->with('<'.$code.'/>')
            ->andReturn(new DocumentsSnapshot(DetailSnapshotStatus::Complete, []));
        app()->instance($mapper::class, $mapper);
        app()->instance($parser::class, $parser);
        $baseline = json_decode(file_get_contents(resource_path('crawler/health/eis-baseline.json')), true);
        $baseline['platform'] = $code;
        $baseline['card_tabs']['documents']['anchors'] = [['selector' => $code, 'critical' => true, 'min_matches' => 1]];
        $disk = Storage::disk('local');
        $disk->put('baseline-'.$code.'.json', json_encode($baseline));
        $map[$code] += ['listing_mapper' => $mapper::class, 'detail_parser' => $parser::class, 'block_size' => $size, 'baseline_path' => $disk->path('baseline-'.$code.'.json')];
    }
    $adapters = new PlatformAdapterRegistry($map);
    app()->instance(PlatformAdapterRegistry::class, $adapters);
    foreach (['alpha', 'beta', 'alpha'] as $code) {
        $registry = adapterRegistryWithPlatform($code, 'main');
        expect($adapters->listingMapper($registry)->map([$code.'_id' => '1'], new Crawler)->reason)->toBe($code)
            ->and($adapters->detailParser($registry)->documents('<'.$code.'/>')->status)->toBe(DetailSnapshotStatus::Complete)
            ->and($adapters->blockSize($registry))->toBe($code === 'alpha' ? 17 : 29)
            ->and(app(HealthBaselineReader::class)->read($registry)->platform)->toBe($code);
    }
    $bad = $map;
    unset($bad['beta']['listing_mapper']);
    expect(fn () => (new PlatformAdapterRegistry($bad))->listingMapper(adapterRegistryWithPlatform('beta', 'main')))
        ->toThrow(PlatformAdapterConfigurationException::class);
});
