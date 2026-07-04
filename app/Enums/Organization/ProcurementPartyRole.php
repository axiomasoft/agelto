<?php

declare(strict_types=1);

namespace App\Enums\Organization;

/**
 * Роль организации в закупке (OCDS parties-подход): одна организация может
 * встречаться в разных ролях в разных закупках, поэтому роль хранится в pivot
 * `org_procurement_parties`, а не отдельными FK-колонками на каждую роль.
 */
enum ProcurementPartyRole: string
{
    case Customer = 'customer';
    case Placer = 'placer';
    case AuthorizedBody = 'authorized_body';
    case SpecializedOrganization = 'specialized_organization';
    case PlatformOperator = 'platform_operator';
    case Winner = 'winner';
    case Participant = 'participant';
    case Guarantor = 'guarantor';
}
