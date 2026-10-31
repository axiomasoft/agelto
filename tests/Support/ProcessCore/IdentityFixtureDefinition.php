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
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Platform\UnsupportedCapability;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;

final readonly class IdentityFixtureDefinition implements PlatformDefinitionProvider
{
    public function definition(): PlatformDefinition
    {
        $unsupported = new UnsupportedCapability('Persistence fixture only.');

        return new PlatformDefinition(new PlatformCode('identity_fixture'), new AdapterVersion('1'),
            new RegistryDefinitions([
                new RegistryDefinition('main', new SourceIdentifierType('notice:main')),
                new RegistryDefinition('secondary', new SourceIdentifierType('notice:secondary')),
            ]), new EndpointCatalog([]), $unsupported, $unsupported, $unsupported, $unsupported,
            new DiagnosticChecks([]), new SourceTargetDefinitions('identity_fixture', []), $unsupported);
    }
}
