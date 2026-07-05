<?php

declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\ProcurementParty;
use App\Models\Procurement\Procurement;

/**
 * Персистентность роли организации в закупке (`org_procurement_parties`) и денормализации
 * `prc_procurements.customer_org_id` для процессора вкладки `common-info` (P3.4, D15).
 */
final readonly class ProcurementPartyStoreRepository
{
    /**
     * @param  array<string, string>|null  $attributes
     */
    public function upsertParty(int $procurementId, int $organizationId, ProcurementPartyRole $role, ?array $attributes): void
    {
        ProcurementParty::query()->updateOrCreate(
            [
                'procurement_id' => $procurementId,
                'organization_id' => $organizationId,
                'role' => $role->value,
            ],
            [
                'attributes' => $attributes,
            ],
        );
    }

    public function setCustomerOrg(Procurement $procurement, int $organizationId): void
    {
        if ($procurement->customer_org_id === $organizationId) {
            return;
        }

        $procurement->update(['customer_org_id' => $organizationId]);
    }
}
