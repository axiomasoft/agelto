<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

use App\Enums\Procurement\BidStatus;
use App\Services\Organization\OrganizationResolver;

/**
 * Заявка/предложение участника, построчно из таблицы результатов вкладки
 * `supplier-results` (`SupplierResultsParser::parse()`). Организация участника-
 * победителя обогащена ИНН/ОГРН из блока «Победитель» той же вкладки (совпадение
 * по наименованию) — иначе резолвер {@see OrganizationResolver}
 * создал бы для победителя две разные записи `org_organizations` (по имени и по ИНН).
 *
 * `organization === null` — участник БЕЗ надёжного идентификатора (ИНН/ОГРН): строка
 * таблицы результатов содержит только наименование, а `OrganizationResolver::find()`
 * без ИНН/ОГРН не отличает одно юрлицо от другого — резолв по голому имени склеил бы
 * разных участников в одну `org_organizations` (обнаружено ревью P3.7). Для такой
 * заявки `prc_bids.organization_id` остаётся `null`.
 */
final readonly class BidData
{
    public function __construct(
        public ?OrganizationData $organization,
        public ?int $participantOrdinal,
        public ?float $price,
        public BidStatus $status,
    ) {}
}
