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
        DB::transaction(function () use ($procurement, $parties): void {
            foreach ($parties as $party) {
                $organization = $this->organizationResolver->resolve($party->organization);

                $this->storeRepository->upsertParty(
                    procurementId: $procurement->id,
                    organizationId: $organization->id,
                    role: $party->role,
                    attributes: $this->contactAttributes($party),
                );

                if ($party->role === ProcurementPartyRole::Customer) {
                    $this->storeRepository->setCustomerOrg($procurement, $organization->id);
                }
            }
        });
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
