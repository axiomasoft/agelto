<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Platform;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\StatusMapping;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class StatusMappingStoreRepository
{
    private const string RegistrySourceStageUnique = 'prc_status_mappings_registry_source_stage_unique';

    public function createExactUnknown(int $registryId, string $sourceStage): StatusMapping
    {
        // Roll back only this INSERT on a concurrent discovery. The caller's publication
        // transaction must remain usable when StatusMappingService rereads the winner.
        return (new StatusMapping)->getConnection()->transaction(fn (): StatusMapping => StatusMapping::query()->create([
            'registry_id' => $registryId,
            'source_stage' => $sourceStage,
            'canonical_status' => ProcurementStatus::Unknown->value,
        ]));
    }

    public function isExactRegistryRace(UniqueConstraintViolationException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), self::RegistrySourceStageUnique);
    }
}
