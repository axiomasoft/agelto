<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Platform\Capabilities\SupportedDetailCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingCapability;
use App\Dto\Procurement\Platform\Capabilities\UnsupportedCapability;
use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Platform\Definition\EndpointCatalog;
use App\Dto\Procurement\Platform\Definition\EndpointDefinition;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Definition\RegistryDefinition;
use App\Dto\Procurement\Platform\Definition\RegistryDefinitions;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Platform\Diagnostics\DiagnosticChecks;
use App\Dto\Procurement\Platform\Identity\AdapterVersion;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentifierType;
use App\Enums\Procurement\Network\ResponseKind;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;

/** Testing registration only. JSON transport/parser are owned by the adapter slice. */
final readonly class JsonProcurementDefinition implements PlatformDefinitionProvider
{
    public function definition(): PlatformDefinition
    {
        $profile = new RequestProfile('json.fixture', 2, 5, 10, 2_097_152, 6_291_456, 3, 3, 2, [200, 600], ResponseKind::Text, 'UTF-8', 1);
        $endpoint = new EndpointDefinition('detail', 'json.detail', 'http://fixture-http:8080/procurements', $profile);
        $pending = new UnsupportedCapability('JSON adapter not activated.');

        return new PlatformDefinition(
            code: new PlatformCode('json'),
            version: new AdapterVersion('1'),
            registries: new RegistryDefinitions([new RegistryDefinition('fz44', new SourceIdentifierType('opaque:notice'))]),
            endpoints: new EndpointCatalog([$endpoint]),
            listing: new SupportedListingCapability(JsonListingSource::class, ['detail']),
            detail: new SupportedDetailCapability(JsonDetailSource::class, ['detail']),
            status: $pending,
            documents: $pending,
            checks: new DiagnosticChecks([]),
            sourceTargets: new SourceTargetDefinitions('json', [
                new SourceTargetDefinition('json.detail', 'detail', CanonicalOrigin::fromUrl($endpoint->url), [$profile]),
            ]),
            partitioning: $pending,
        );
    }
}
