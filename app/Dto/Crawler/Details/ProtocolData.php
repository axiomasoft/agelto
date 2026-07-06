<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

use App\Enums\Procurement\ProtocolType;
use Illuminate\Support\Carbon;

/**
 * Протокол процедуры, разобранный из вкладки `supplier-results`
 * (`SupplierResultsParser::parse()`). `type` — значение закрытого codelist
 * {@see ProtocolType} для 44-ФЗ либо сырой текст для
 * 223-ФЗ (open-ended, `01-database.md` §4) — здесь простая строка, маппинг
 * в enum не блокирует незнакомые формулировки.
 */
final readonly class ProtocolData
{
    public function __construct(
        public string $type,
        public ?string $sourceNumber,
        public ?Carbon $signedAt,
        public ?Carbon $publishedAt,
    ) {}
}
