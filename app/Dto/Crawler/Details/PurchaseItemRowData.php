<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

/**
 * Одна позиция объекта закупки из таблицы `purchase-items-block` вкладки `common-info`
 * (сырые коды классификаторов — резолв в `okpd2_ref_id`/`ktru_ref_id` делает процессор
 * через `ClassifierResolver`, не парсер).
 */
final readonly class PurchaseItemRowData
{
    public function __construct(
        public int $positionNumber,
        public string $name,
        public ?string $characteristics,
        public string $classifierScheme,
        public ?string $classifierCodeOkpd2,
        public ?string $classifierCodeKtru,
        public ?string $unit,
        public ?string $quantity,
        public ?string $unitPrice,
        public ?string $totalCost,
    ) {}
}
