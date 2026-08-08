<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

use App\Enums\Procurement\AwardStatus;
use Illuminate\Support\Carbon;

/**
 * Итог закупки (OCDS award) — подтверждённый результат + итоговая цена, разобранные из
 * вкладки `supplier-results` (`SupplierResultsParser::parse()`). `winner === null` означает,
 * что ЕИС уже опубликовала ранг победителя, но ещё не раскрыла идентифицируемую организацию;
 * такой award сохраняется и может быть монотонно обогащён позднее (D128).
 * Лот не разбирается в этом слайсе (44-ФЗ виртуальный лот №1 — `lot_id` не
 * заполняется парсером, см. докблок `SupplierResultsProcessor`). `awardedAt` —
 * дата протокола итогов (собственной даты у блока «Победитель» в источнике нет).
 */
final readonly class AwardData
{
    public function __construct(
        public ?OrganizationData $winner,
        public ?float $finalPrice,
        public AwardStatus $status,
        public ?Carbon $awardedAt = null,
    ) {}
}
