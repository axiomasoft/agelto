<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Platform\AdapterVersion;
use App\Dto\Procurement\Platform\DiagnosticChecks;
use App\Dto\Procurement\Platform\EndpointCatalog;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\RegistryDefinition;
use App\Dto\Procurement\Platform\RegistryDefinitions;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\UnsupportedCapability;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;

/** Machinery fixture; no advertised fetch capabilities. */
final readonly class NetworkFixtureDefinition implements PlatformDefinitionProvider
{
    public function definition(): PlatformDefinition
    {
        $unsupported = new UnsupportedCapability('Catalog machinery fixture only.');

        return new PlatformDefinition(new PlatformCode('fixture'), new AdapterVersion('1'),
            new RegistryDefinitions([new RegistryDefinition('fixture', new SourceIdentifierType('fixture:notice'))]), new EndpointCatalog([]),
            $unsupported, $unsupported, $unsupported, $unsupported, new DiagnosticChecks([]),
            NetworkFixtures::catalog(), $unsupported);
    }
}
