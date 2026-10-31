<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class PlatformDefinition
{
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
    ) {}

    public function validateIdentity(string $registryCode, SourceIdentity $identity): void
    {
        $registry = $this->registries->get($registryCode);
        if ($identity->platform->value !== $this->code->value
            || $identity->type->value !== $registry->primaryIdentityType->value) {
            throw new InvalidPlatformDefinition('Identity does not belong to requested platform/registry.');
        }
    }
}
