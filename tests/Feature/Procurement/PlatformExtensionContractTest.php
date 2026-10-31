<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Config\Repository;
use Tests\Support\Platforms\JsonProcurement\JsonListingSource;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;

function extensionRegistry(): PlatformRegistry
{
    $config = new Repository(config()->all());
    $config->set('platforms.definitions', ['eis' => EisDefinition::class, 'json' => JsonProcurementDefinition::class]);

    return new PlatformRegistry($config);
}

test('две площадки с совпадающим registry code получают независимые capability', function (): void {
    $registry = extensionRegistry();
    $eis = $registry->get(new PlatformCode('eis'));
    $json = $registry->get(new PlatformCode('json'));

    expect($eis->registries->get('fz44')->primaryIdentityType->value)->toBe('registry:44fz')
        ->and($json->registries->get('fz44')->primaryIdentityType->value)->toBe('opaque:notice')
        ->and($eis->listing)->toBeInstanceOf(SupportedListingCapability::class)
        ->and($json->listing)->toBeInstanceOf(SupportedListingCapability::class)
        ->and($eis->listing->implementation)->toBe(EisListingSource::class)
        ->and($json->listing->implementation)->toBe(JsonListingSource::class);
});

test('удалённая capability завершается explicit fail-closed ошибкой', function (): void {
    $platform = Platform::factory()->create(['code' => 'json']);
    $registry = Registry::factory()->for($platform)->create(['code' => 'fz44']);
    $profile = new Crawler(['registry_id' => $registry->id]);
    $profile->setRelation('registry', $registry);

    // JSON supports listing but declares no profile preparer. There is no EIS fallback.
    expect(fn () => (new AcquisitionProfiles(extensionRegistry()))->preparer($profile))
        ->toThrow(InvalidArgumentException::class, 'Platform has no acquisition profile capability.');
});
