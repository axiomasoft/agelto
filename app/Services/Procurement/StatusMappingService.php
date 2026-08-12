<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\StatusResolution;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;
use App\Models\Procurement\Platform\StatusMapping;
use App\Repositories\Procurement\Platform\StatusMappingReadRepository;
use App\Repositories\Procurement\Platform\StatusMappingStoreRepository;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Резолв сырого статуса источника в канонический (`prc_status_mappings` — данные, не код).
 * Незнакомая пара (registry_id, source_stage) АВТОМАТИЧЕСКИ заводит строку с `canonical_status='unknown'`
 * (см. `plans/V2-CORE/plan.md` Decision Log D5) — оператор видит и правит её в админке, не роется в логах.
 */
final readonly class StatusMappingService
{
    public function __construct(
        private StatusMappingReadRepository $read,
        private StatusMappingStoreRepository $store,
    ) {}

    public function resolve(int $registryId, string $sourceStage): StatusResolution
    {
        $mapping = $this->read->findExactRegistryMapping(registryId: $registryId, sourceStage: $sourceStage);

        if ($mapping !== null) {
            return $this->resolution(mapping: $mapping, isNewlyMapped: false);
        }

        $mapping = $this->read->findPlatformDefaultForRegistry(registryId: $registryId, sourceStage: $sourceStage);

        if ($mapping !== null) {
            return $this->resolution(mapping: $mapping, isNewlyMapped: false);
        }

        try {
            $mapping = $this->store->createExactUnknown(registryId: $registryId, sourceStage: $sourceStage);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->store->isExactRegistryRace(exception: $exception)) {
                throw $exception;
            }

            $mapping = $this->read->findExactRegistryMapping(registryId: $registryId, sourceStage: $sourceStage);

            if ($mapping === null) {
                throw $exception;
            }

            return $this->resolution(mapping: $mapping, isNewlyMapped: false);
        }

        return $this->resolution(mapping: $mapping, isNewlyMapped: true);
    }

    private function resolution(StatusMapping $mapping, bool $isNewlyMapped): StatusResolution
    {
        return new StatusResolution(
            status: ProcurementStatus::from($mapping->canonical_status),
            isNewlyMapped: $isNewlyMapped,
            matchedApplicability: StatusMappingApplicability::fromScopeIds(
                platformId: $mapping->platform_id,
                registryId: $mapping->registry_id,
            ),
        );
    }
}
