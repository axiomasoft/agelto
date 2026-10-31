<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Platform\AdapterVersion;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\DiagnosticChecks;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\RegistryDefinition;
use App\Dto\Procurement\Platform\RegistryDefinitions;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Platform\UnsupportedCapability;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;
use Illuminate\Contracts\Config\Repository;

final readonly class EisDefinition implements PlatformDefinitionProvider
{
    public EisPolicy $policy;

    public function __construct(Repository $config)
    {
        $this->policy = new EisPolicy($config);
    }

    public function definition(): PlatformDefinition
    {
        $targets = [];
        foreach ($this->policy->endpoints->endpoints as $endpoint) {
            $targets[] = new SourceTargetDefinition($endpoint->targetKey, $endpoint->code,
                CanonicalOrigin::fromUrl($endpoint->url), [$endpoint->profile]);
        }
        $pending = new UnsupportedCapability('Typed adapter not activated; owned by P2.10–P2.12.');

        return new PlatformDefinition(
            code: new PlatformCode('eis'),
            version: new AdapterVersion('1'),
            registries: new RegistryDefinitions([new RegistryDefinition('fz44', new SourceIdentifierType('registry:44fz'))]),
            endpoints: $this->policy->endpoints,
            listing: $pending,
            detail: $pending,
            status: new UnsupportedCapability('Status adapter belongs to acquisition cutover.'),
            documents: $pending,
            checks: new DiagnosticChecks([]),
            sourceTargets: new SourceTargetDefinitions('eis', $targets),
            partitioning: $pending,
        );
    }
}
