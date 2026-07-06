<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

use App\Enums\Organization\ProcurementPartyRole;

/**
 * Одна строка участия организации в закупке (роль + организация как упомянута в
 * источнике + контактное лицо этой роли — контакт живёт в `prc_procurement_parties.attributes`,
 * не в `org_organizations`, `01-database.md` §2).
 */
final readonly class PartyData
{
    public function __construct(
        public ProcurementPartyRole $role,
        public OrganizationData $organization,
        public ?string $contactPersonName = null,
        public ?string $contactPersonPosition = null,
    ) {}
}
