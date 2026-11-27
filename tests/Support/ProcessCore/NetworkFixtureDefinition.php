<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Platform\Capabilities\UnsupportedCapability;
use App\Dto\Procurement\Platform\Definition\EndpointCatalog;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Definition\RegistryDefinition;
use App\Dto\Procurement\Platform\Definition\RegistryDefinitions;
use App\Dto\Procurement\Platform\Diagnostics\DiagnosticChecks;
use App\Dto\Procurement\Platform\Identity\AdapterVersion;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
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
