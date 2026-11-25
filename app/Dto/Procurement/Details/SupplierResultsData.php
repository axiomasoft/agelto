<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

use App\Enums\Procurement\BidStatus;

/**
 * Итоги вкладки `supplier-results` карточки закупки ЕИС (`SupplierResultsParser::parse()`):
 * протокол, построчные заявки участников и итог (победитель + цена). Пустая вкладка
 * (незавершённая закупка) → все поля `null`/`[]`, `SupplierResultsProcessor` делает no-op.
 */
final readonly class SupplierResultsData
{
    /**
     * @param  list<BidData>  $bids
     */
    public function __construct(
        public ?ProtocolData $protocol,
        public array $bids,
        public ?AwardData $award,
    ) {}

    public function hasMultipleWinners(): bool
    {
        return count(array_filter($this->bids, static fn (BidData $bid): bool => $bid->status === BidStatus::Winner)) > 1;
    }
}
