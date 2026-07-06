<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\StatusResolution;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\StatusMapping;

/**
 * Резолв сырого статуса источника в канонический (`prc_status_mappings` — данные, не код).
 * Незнакомая пара (registry_id, source_status) АВТОМАТИЧЕСКИ заводит строку с `canonical_status='unknown'`
 * (см. `plans/V2-CORE/plan.md` Decision Log D5) — оператор видит и правит её в админке, не роется в логах.
 */
final readonly class StatusMappingService
{
    public function resolve(int $registryId, string $sourceStatus): StatusResolution
    {
        $mapping = StatusMapping::query()->firstOrCreate(
            attributes: [
                'registry_id' => $registryId,
                'source_status' => $sourceStatus,
            ],
            values: [
                'canonical_status' => ProcurementStatus::Unknown->value,
            ],
        );

        return new StatusResolution(
            status: ProcurementStatus::from($mapping->canonical_status),
            isNewlyMapped: $mapping->wasRecentlyCreated,
        );
    }
}
