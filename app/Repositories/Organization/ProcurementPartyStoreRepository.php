<?php

declare(strict_types=1);

namespace App\Repositories\Organization;

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;

/**
 * Персистентность роли организации в закупке (`prc_procurement_parties`) и денормализации
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

    public function setCustomerOrg(Procurement $procurement, ?int $organizationId): void
    {
        if ($procurement->customer_org_id === $organizationId) {
            return;
        }

        $procurement->update(['customer_org_id' => $organizationId]);
    }

    /**
     * @param  list<array{organization_id: int, role: ProcurementPartyRole}>  $partyKeys
     */
    public function deletePartiesMissingFromSnapshot(int $procurementId, array $partyKeys): void
    {
        $query = ProcurementParty::query()->where('procurement_id', $procurementId)
            ->whereIn('role', [
                ProcurementPartyRole::Customer,
                ProcurementPartyRole::Placer,
                ProcurementPartyRole::AuthorizedBody,
                ProcurementPartyRole::AuthorizedInstitution,
                ProcurementPartyRole::SpecializedOrganization,
                ProcurementPartyRole::PlatformOperator,
            ]);

        if ($partyKeys === []) {
            $query->delete();

            return;
        }

        $currentIds = ProcurementParty::query()
            ->where('procurement_id', $procurementId)
            ->where(function ($query) use ($partyKeys): void {
                foreach ($partyKeys as $partyKey) {
                    $query->orWhere(function ($query) use ($partyKey): void {
                        $query->where('organization_id', $partyKey['organization_id'])
                            ->where('role', $partyKey['role']->value);
                    });
                }
            })
            ->pluck('id');

        $query->whereNotIn('id', $currentIds)->delete();
    }

    /**
     * @param  list<int>  $winnerOrgIds
     */
    public function syncWinnerRoles(int $procurementId, array $winnerOrgIds): void
    {
        ProcurementParty::query()
            ->where('procurement_id', $procurementId)
            ->where('role', ProcurementPartyRole::Winner->value)
            ->whereNotIn('organization_id', $winnerOrgIds)
            ->delete();
    }
}
