<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

use App\Enums\Procurement\BidStatus;
use App\Services\Organization\OrganizationResolver;

/**
 * Заявка/предложение участника, построчно из таблицы результатов вкладки
 * `supplier-results` (`SupplierResultsParser::parse()`). Реальная success-вкладка
 * может скрывать реквизиты участника, оставляя только анонимный номер заявки и ранг.
 *
 * `organization === null` — участник БЕЗ надёжного идентификатора (ИНН/ОГРН): строка
 * таблицы результатов не содержит реквизитов, а `OrganizationResolver::find()`
 * без ИНН/ОГРН не отличает одно юрлицо от другого — резолв по голому имени склеил бы
 * разных участников в одну `org_organizations` (обнаружено ревью). Для такой
 * заявки `prc_bids.organization_id` остаётся `null`.
 * `bidNumber` — анонимный идентификатор участника из отдельной колонки ЕИС; он не равен
 * `participantOrdinal`, полученному по результатам рассмотрения заявок.
 */
final readonly class BidData
{
    public function __construct(
        public ?OrganizationData $organization,
        public ?int $participantOrdinal,
        public ?string $price,
        public BidStatus $status,
        public ?string $bidNumber = null,
    ) {}
}
