<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

/** Neutral common attributes and independently typed adapter extension. */
final readonly class CommonInfoData
{
    public function __construct(
        public ?object $extension = null,
        public ?string $contactPersonName = null,
        public ?string $contactPersonEmail = null,
        /** @var list<FinancingRowData> */
        public array $financingRows = [],
        /** @var list<PurchaseItemRowData> */
        public array $items = [],
    ) {}
}
