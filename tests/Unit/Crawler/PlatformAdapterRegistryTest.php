<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Platform\Capabilities\SupportedDetailCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingCapability;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Platforms\Eis\EisDetailSource;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Config\Repository;
use Tests\Support\Platforms\JsonProcurement\JsonDetailSource;
use Tests\Support\Platforms\JsonProcurement\JsonListingSource;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;

function acquisitionDefinitionConfig(): Repository
{
    $config = new Repository(config()->all());
    $config->set('platforms.definitions', ['eis' => EisDefinition::class, 'json' => JsonProcurementDefinition::class]);

    return $config;
}

test('the same registry code on two platforms resolves independent typed capabilities', function (): void {
    $registry = new PlatformRegistry(acquisitionDefinitionConfig());
    $eis = $registry->get(new PlatformCode('eis'));
    $json = $registry->get(new PlatformCode('json'));
    expect($eis->registries->get('fz44')->primaryIdentityType->value)->toBe('registry:44fz')
        ->and($json->registries->get('fz44')->primaryIdentityType->value)->toBe('opaque:notice')
        ->and($eis->listing)->toBeInstanceOf(SupportedListingCapability::class)
        ->and($eis->listing->implementation)->toBe(EisListingSource::class)
        ->and($json->listing)->toBeInstanceOf(SupportedListingCapability::class)
        ->and($json->listing->implementation)->toBe(JsonListingSource::class);
});

test('missing platforms and registries fail closed with their exact identity', function (): void {
    $registry = new PlatformRegistry(acquisitionDefinitionConfig());
    expect(fn () => $registry->get(new PlatformCode('unknown')))->toThrow(InvalidPlatformDefinition::class, 'unknown');
    expect(fn () => $registry->get(new PlatformCode('eis'))->registries->get('missing'))
        ->toThrow(InvalidPlatformDefinition::class, 'missing');
});

test('invalid provider class strings are rejected before runtime capability resolution', function (): void {
    $config = acquisitionDefinitionConfig();
    $config->set('platforms.definitions.eis', stdClass::class);
    expect(fn () => new PlatformRegistry($config))->toThrow(InvalidPlatformDefinition::class, 'platforms.definitions.eis');
});

test('untyped definition maps are rejected during registry construction', function (): void {
    foreach (['csv', ['eis' => ['listing' => EisListingSource::class]]] as $malformed) {
        $config = acquisitionDefinitionConfig();
        $config->set('platforms.definitions', $malformed);
        expect(fn () => new PlatformRegistry($config))->toThrow(InvalidPlatformDefinition::class, 'platforms.definitions');
    }
});

test('two incompatible adapters retain their own parsers endpoints and policies in one registry', function (): void {
    $config = acquisitionDefinitionConfig();
    $config->set('platforms.eis.block_size', 17);
    $registry = new PlatformRegistry($config);
    foreach (['eis', 'json', 'eis'] as $code) {
        $definition = $registry->get(new PlatformCode($code));
        expect($definition->detail)->toBeInstanceOf(SupportedDetailCapability::class)
            ->and($definition->detail->implementation)->toBe($code === 'eis' ? EisDetailSource::class : JsonDetailSource::class);
    }
    expect((new EisPolicy($config))->blockSize)->toBe(17)
        ->and($registry->get(new PlatformCode('eis'))->endpoints->get('listing')->profile->totalSeconds)->toBe(90)
        ->and($registry->get(new PlatformCode('json'))->endpoints->get('detail')->profile->totalSeconds)->toBe(10);
});
