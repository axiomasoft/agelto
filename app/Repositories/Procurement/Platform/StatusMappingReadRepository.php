<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Platform;

use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;

final readonly class StatusMappingReadRepository
{
    public function findExactRegistryMapping(int $registryId, string $sourceStage): ?StatusMapping
    {
        return StatusMapping::query()
            ->where('registry_id', $registryId)
            ->where('source_stage', $sourceStage)
            ->first();
    }

    public function findPlatformDefaultForRegistry(int $registryId, string $sourceStage): ?StatusMapping
    {
        $platformId = Registry::query()
            ->whereKey($registryId)
            ->soleValue('platform_id');

        return StatusMapping::query()
            ->where('platform_id', $platformId)
            ->where('source_stage', $sourceStage)
            ->first();
    }
}
