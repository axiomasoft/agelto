<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;

/**
 * Результат резолва сырого статуса: канон + признак, что маппинг для этой пары
 * (registry_id, source_stage) заведён только что (см. `StatusMappingService::resolve()`).
 */
final readonly class StatusResolution
{
    public function __construct(
        public ProcurementStatus $status,
        public bool $isNewlyMapped,
        public ?StatusMappingApplicability $matchedApplicability = null,
    ) {}
}
