<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Network\AdapterContractRef;
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
use App\Dto\Procurement\Platform\SupportedListingCapability;
use App\Dto\Procurement\Platform\SupportedListingPartitioningCapability;
use App\Dto\Procurement\Platform\UnsupportedCapability;
use App\Enums\Procurement\Network\ResponseKind;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
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
        // Existing reference/document consumers retain these config keys until their cutover.
        // Declaration assembly stays here; no runtime adapter or second catalog is constructed.
        $referenceUrl = $config->get('crawler.okpd2.children_endpoint');
        $hosts = $config->get('crawler.documents_allowed_hosts');
        if (! is_string($referenceUrl) || ! is_array($hosts) || ! array_is_list($hosts) || $hosts === []) {
            throw new InvalidPlatformDefinition('crawler.okpd2.children_endpoint/documents_allowed_hosts: invalid catalog declaration.');
        }
        $targets['okpd2'] = new SourceTargetDefinition('okpd2', 'reference_sync', CanonicalOrigin::fromUrl($referenceUrl), [
            new RequestProfile('eis.reference', 5, 10, 20, 2_097_152, 6_291_456, 3, 2, 2, [200], ResponseKind::Text, 'UTF-8', 1),
        ]);
        foreach ($hosts as $host) {
            if (! is_string($host) || $host === '') {
                throw new InvalidPlatformDefinition('crawler.documents_allowed_hosts: expected nonempty host strings.');
            }
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
            status: new UnsupportedCapability('Status adapter belongs to acquisition cutover.'),
            documents: new UnsupportedCapability('Document access adapter belongs to document cutover.'),
            checks: new DiagnosticChecks(['listing_filter' => Diagnostics\ListingFilterCheck::class]),
            sourceTargets: $this->targets,
            partitioning: new SupportedListingPartitioningCapability(EisListingPartitioner::class, ['listing']),
            extension: new ExtensionDescriptor(EisDetailExtension::class,
                EisExtensionPersister::class, new AdapterVersion('1'), new AdapterVersion('1')),
            uiExtension: EisUiExtension::class,
        );
    }
}
