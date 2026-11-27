<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\Capabilities\SupportedListingCapability;
use App\Dto\Procurement\Platform\Capabilities\UnsupportedCapability;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Platforms\Eis\Listing\EisProfilePreparer;
use App\Services\Procurement\Acquisition\Listing\AcquisitionProfiles;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Config\Repository;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;

function sourceDefinitionRegistry(array $definitions): PlatformRegistry
{
    $config = new Repository(config()->all());
    $config->set('platforms.definitions', $definitions);

    return new PlatformRegistry($config);
}

function sourceDefinitionProfile(string $code, string $registryCode = 'fz44'): Crawler
{
    $platform = new Platform;
    $platform->code = $code;
    $registry = new Registry;
    $registry->code = $registryCode;
    $registry->setRelation('platform', $platform);
    $profile = new Crawler;
    $profile->setRelation('registry', $registry);

    return $profile;
}

test('typed definition resolves the exact listing implementation and profile preparer', function (): void {
    $registry = sourceDefinitionRegistry(['eis' => EisDefinition::class]);
    $definition = $registry->get(new PlatformCode('eis'));
    expect($definition->listing)->toBeInstanceOf(SupportedListingCapability::class)
        ->and($definition->listing->implementation)->toBe(EisListingSource::class)
        ->and((new AcquisitionProfiles($registry))->preparer(sourceDefinitionProfile('eis')))->toBeInstanceOf(EisProfilePreparer::class);
});

test('an unsupported capability stays explicit and never falls back to another platform', function (): void {
    $registry = sourceDefinitionRegistry(['eis' => EisDefinition::class, 'json' => JsonProcurementDefinition::class]);
    $json = $registry->get(new PlatformCode('json'));
    config(['crawler.platforms.json.fallback_method' => 'eis']);
    expect($json->status)->toBeInstanceOf(UnsupportedCapability::class)
        ->and($json->status->reason)->not->toBeEmpty();
    expect(fn () => (new AcquisitionProfiles($registry))->preparer(sourceDefinitionProfile('json')))
        ->toThrow(InvalidArgumentException::class, 'no acquisition profile capability');
});

test('missing definitions reject resolution even when legacy primary and fallback config are present', function (): void {
    $registry = sourceDefinitionRegistry([]);
    config(['crawler.platforms.test.source_methods' => ['csv' => EisListingSource::class, 'html' => EisListingSource::class]]);
    expect(fn () => $registry->get(new PlatformCode('test')))->toThrow(InvalidPlatformDefinition::class, 'test');
});

test('missing profile capability cannot resolve an implicit default preparer', function (): void {
    $registry = sourceDefinitionRegistry(['json' => JsonProcurementDefinition::class]);
    expect(fn () => (new AcquisitionProfiles($registry))->preparer(sourceDefinitionProfile('json')))
        ->toThrow(InvalidArgumentException::class, 'no acquisition profile capability');
});

test('a registry absent from the exact platform definition fails closed before preparation', function (): void {
    $registry = sourceDefinitionRegistry(['eis' => EisDefinition::class]);
    expect(fn () => (new AcquisitionProfiles($registry))->preparer(sourceDefinitionProfile('eis', 'missing')))
        ->toThrow(InvalidPlatformDefinition::class, 'missing');
});

test('an invalid registered provider fails at construction without resolving a fallback', function (): void {
    expect(fn () => sourceDefinitionRegistry(['eis' => stdClass::class]))
        ->toThrow(InvalidPlatformDefinition::class, 'platforms.definitions.eis');
});
