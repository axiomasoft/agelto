<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

/**
 * Результат разбора вкладки `common-info` карточки закупки ЕИС (`CommonInfoParser::parse()`).
 * Плоские nullable-поля 1:1 к `prc_eis_details` (заполняются только те блоки, что реально
 * присутствуют на странице — отсутствие блока не является ошибкой парсинга) + списки строк
 * финансирования (`prc_financing`, без итоговой строки «Всего») и позиций (`prc_items`).
 *
 * @param  list<FinancingRowData>  $financingRows
 * @param  list<PurchaseItemRowData>  $items
 */
final readonly class CommonInfoData
{
    public function __construct(
        public ?int $ikzYear = null,
        public ?string $ikzCustomerCode = null,
        public ?string $ikzPlanNumber = null,
        public ?string $ikzPlangraphNumber = null,
        public ?string $ikzOkpdClass = null,
        public ?string $ikzOkpdSubclass = null,
        public ?string $ikzOkpdGroup = null,
        public ?string $ikzKvr = null,
        public ?string $etpName = null,
        public ?string $etpUrl = null,
        public ?string $contactPersonName = null,
        public ?string $contactPersonEmail = null,
        public array $financingRows = [],
        public array $items = [],
    ) {}
}
