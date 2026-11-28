<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

use App\Dto\Procurement\Platform\Capabilities\SupportedDetailCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedDocumentAccessCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Platform\Capabilities\SupportedStatusCapability;
use App\Dto\Procurement\Platform\Capabilities\UnsupportedCapability;
use App\Dto\Procurement\Platform\Diagnostics\DiagnosticChecks;
use App\Dto\Procurement\Platform\Identity\AdapterVersion;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class PlatformDefinition
{
    /**
     * @param  array<string, string>  $stageRouteProfiles
     */
    public function __construct(
        public PlatformCode $code,
        public AdapterVersion $version,
        public RegistryDefinitions $registries,
        public EndpointCatalog $endpoints,
        public SupportedListingCapability|UnsupportedCapability $listing,
        public SupportedDetailCapability|UnsupportedCapability $detail,
        public SupportedStatusCapability|UnsupportedCapability $status,
        public SupportedDocumentAccessCapability|UnsupportedCapability $documents,
        public DiagnosticChecks $checks,
        public SourceTargetDefinitions $sourceTargets,
        public SupportedListingPartitioningCapability|UnsupportedCapability $partitioning,
        public ?ExtensionDescriptor $extension = null,
        public ?string $uiExtension = null,
        public ?AcquisitionProfileDescriptor $acquisitionProfile = null,
        public array $stageRouteProfiles = [],
    ) {}

    public function stageRouteProfileKey(StageCode $stage): ?string
    {
        return $this->stageRouteProfiles[$stage->value] ?? null;
    }

    public function validateIdentity(string $registryCode, SourceIdentity $identity): void
    {
        $registry = $this->registries->get($registryCode);
        if ($identity->platform->value !== $this->code->value
            || $identity->type->value !== $registry->primaryIdentityType->value) {
            throw new InvalidPlatformDefinition('Identity does not belong to requested platform/registry.');
        }
    }
}
