<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Platform\AdapterVersion;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\DiagnosticChecks;
use App\Dto\Procurement\Platform\EndpointCatalog;
use App\Dto\Procurement\Platform\EndpointDefinition;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\RegistryDefinition;
use App\Dto\Procurement\Platform\RegistryDefinitions;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Platform\SupportedDetailCapability;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Platform\UnsupportedCapability;
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
