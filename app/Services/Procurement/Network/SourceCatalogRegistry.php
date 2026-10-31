<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Services\Procurement\Network\Contracts\SourceCatalogProvider;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * Platform catalogs come exclusively from validated Definitions; outbound.catalog_providers
 * is reserved for non-procurement providers. Pure contract validation
 * (уникальная площадка на provider, валидные definitions). Не пишет и не читает БД — authority
 * rows создаёт только явный `platform:sync-catalog`.
 */
final readonly class SourceCatalogRegistry
{
    public function __construct(private OutboundPolicy $policy, private Container $container, private PlatformRegistry $platforms) {}

    /** @return array<string, SourceTargetDefinitions> platform code → targets, стабильный порядок */
    public function catalogs(): array
    {
        $catalogs = [];
        foreach ($this->platforms->all() as $definition) {
            $targets = $definition->sourceTargets;
            $catalogs[$targets->platformCode] = $targets;
        }
        $origins = [];
        foreach ($catalogs as $targets) {
            foreach ($targets->origins() as $origin) {
                $budget = $targets->originBudget($origin, $this->policy->originBudget);
                $key = $origin->value();
                if (! $this->policy->withinBounds($budget) || (isset($origins[$key]) && ! $origins[$key]->equals($budget))) {
                    throw new \InvalidArgumentException('Conflicting or invalid origin budget defaults for '.$key);
                }
                $origins[$key] = $budget;
            }
        }
        ksort($catalogs, SORT_STRING);

        return $catalogs;
    }

    /** @return array<string, ProviderTargetDefinitions> */
    public function providers(): array
    {
        $catalogs = [];
        foreach ($this->policy->catalogProviders as $class) {
            $provider = $this->container->make($class);
            if (! $provider instanceof SourceCatalogProvider) {
                throw new \InvalidArgumentException('Invalid outbound catalog provider.');
            }
            $targets = $provider->sourceTargets();
            if (! $targets instanceof ProviderTargetDefinitions) {
                throw new \InvalidArgumentException('Platform catalogs must be registered through platforms.definitions.');
            }
            $key = $targets->providerKey->value;
            if (isset($catalogs[$key])) {
                throw new \InvalidArgumentException('Duplicate provider catalog: '.$key);
            }
            $catalogs[$key] = $targets;
        }
        ksort($catalogs, SORT_STRING);

        return $catalogs;
    }
}
