<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Platform\AcquisitionProfileDescriptor;
use App\Dto\Procurement\Platform\AdapterVersion;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\DiagnosticChecks;
use App\Dto\Procurement\Platform\ExtensionDescriptor;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\PlatformDefinition;
use App\Dto\Procurement\Platform\RegistryDefinition;
use App\Dto\Procurement\Platform\RegistryDefinitions;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Platform\SupportedDetailCapability;
use App\Dto\Procurement\Platform\SupportedDocumentAccessCapability;
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Platform\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Platform\SupportedStatusCapability;
use App\Enums\Procurement\Network\ResponseKind;
use App\Filament\Platforms\Eis\EisUiExtension;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;
use Illuminate\Contracts\Config\Repository;

final readonly class EisDefinition implements PlatformDefinitionProvider
{
    public EisPolicy $policy;

    private SourceTargetDefinitions $targets;

    public function __construct(Repository $config)
    {
        $this->policy = new EisPolicy($config);
        $targets = [];
        foreach ($this->policy->endpoints->endpoints as $endpoint) {
            $capability = match ($endpoint->code) {
                'card' => 'detail',
                'document' => 'download',
                default => $endpoint->code,
            };
            $targets[$endpoint->targetKey] = new SourceTargetDefinition($endpoint->targetKey, $capability,
                CanonicalOrigin::fromUrl($endpoint->url), [$endpoint->profile],
                adapterContract: in_array($capability, ['listing', 'detail'], true)
                    ? new AdapterContractRef('eis', $capability, '1', '1') : null);
        }
        $referenceUrl = $this->policy->referenceEndpoint;
        $hosts = $this->policy->documentHosts;
        $targets['okpd2'] = new SourceTargetDefinition('okpd2', 'reference_sync', CanonicalOrigin::fromUrl($referenceUrl), [
            new RequestProfile('eis.reference', 5, 10, 20, 2_097_152, 6_291_456, 3, 2, 2, [200], ResponseKind::Text, 'UTF-8', 1),
        ]);
        foreach ($hosts as $host) {
            $origin = CanonicalOrigin::of('https', $host);
            $key = 'document.'.substr($origin->hash()->hex, 0, 16);
            $targets[$key] ??= new SourceTargetDefinition($key, 'download', $origin, [$this->policy->endpoints->get('document')->profile]);
        }
        $this->targets = new SourceTargetDefinitions('eis', array_values($targets));
    }

    public function definition(): PlatformDefinition
    {

        return new PlatformDefinition(
            code: new PlatformCode('eis'),
            version: new AdapterVersion('1'),
            registries: new RegistryDefinitions([new RegistryDefinition('fz44', new SourceIdentifierType('registry:44fz'))]),
            endpoints: $this->policy->endpoints,
            listing: new SupportedListingCapability(EisListingSource::class, ['listing']),
            detail: new SupportedDetailCapability(EisDetailSource::class, ['card']),
            status: new SupportedStatusCapability(EisDetailSource::class, ['card']),
            documents: new SupportedDocumentAccessCapability($this->policy->documentAccessResolver, ['document']),
            checks: new DiagnosticChecks(['listing_filter' => Diagnostics\ListingFilterCheck::class]),
            sourceTargets: $this->targets,
            partitioning: new SupportedListingPartitioningCapability(EisListingPartitioner::class, ['listing']),
            extension: new ExtensionDescriptor(EisDetailExtension::class,
                EisExtensionPersister::class, new AdapterVersion('1'), new AdapterVersion('1')),
            uiExtension: EisUiExtension::class,
            acquisitionProfile: new AcquisitionProfileDescriptor(
                EisSearchFilter::class, Listing\EisProfilePreparer::class, 'ОКПД2.'),
        );
    }
}
