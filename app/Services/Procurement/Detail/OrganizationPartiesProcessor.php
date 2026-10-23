<?php

declare(strict_types=1);

namespace App\Services\Procurement\Detail;

use App\Dto\Procurement\Details\PartyData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Procurement\Procurement;
use App\Repositories\Organization\ProcurementPartyStoreRepository;
use App\Services\Organization\OrganizationResolver;
use Illuminate\Support\Facades\DB;

/**
 * DTO → БД для ролей организаций вкладки `common-info`: резолв каждой организации через
 * {@see OrganizationResolver} (единая точка создания/поиска `org_organizations`), запись
 * `prc_procurement_parties` и денормализации `prc_procurements.customer_org_id` из роли
 * `customer` — через {@see ProcurementPartyStoreRepository} (`01-database.md` §2–§3, D15).
 * Победитель/участники (`winner`/`participant`) — вне этого процессора (P3.7).
 */
final readonly class OrganizationPartiesProcessor
{
    public function __construct(
        private OrganizationResolver $organizationResolver,
        private ProcurementPartyStoreRepository $storeRepository,
    ) {}

    /**
     * @param  list<PartyData>  $parties
     */
    public function process(Procurement $procurement, array $parties): void
    {
        DB::transaction(fn () => $this->sync(procurement: $procurement, parties: $parties));
    }

    /** @param list<PartyData> $parties */
    public function sync(Procurement $procurement, array $parties): void
    {
        $partyKeys = [];
        $customerOrganizationIds = [];

        foreach ($parties as $party) {
            $organization = $this->organizationResolver->resolve($party->organization);

            $this->storeRepository->upsertParty(
                procurementId: $procurement->id,
                organizationId: $organization->id,
                role: $party->role,
                attributes: $this->contactAttributes($party),
            );

            $partyKeys[] = [
                'organization_id' => $organization->id,
                'role' => $party->role,
            ];

            if ($party->role === ProcurementPartyRole::Customer) {
                $customerOrganizationIds[$organization->id] = $organization->id;
            }
        }

        $this->storeRepository->deletePartiesMissingFromSnapshot(
            procurementId: $procurement->id,
            partyKeys: $partyKeys,
        );
        // A scalar FK cannot represent a joint procurement. Every customer remains in parties.
        $this->storeRepository->setCustomerOrg(
            procurement: $procurement,
            organizationId: count($customerOrganizationIds) === 1 ? array_values($customerOrganizationIds)[0] : null,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function contactAttributes(PartyData $party): ?array
    {
        $contact = array_filter([
            'name' => $party->contactPersonName,
            'position' => $party->contactPersonPosition,
        ], static fn (?string $value): bool => $value !== null);

        return $contact !== [] ? $contact : null;
    }
}
