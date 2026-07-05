<?php

declare(strict_types=1);

namespace App\Dto\Crawler\Details;

/**
 * Одна годовая строка блока финансирования вкладки `common-info` (без итоговой строки
 * «Всего» — она производная и в `prc_financing` не пишется).
 */
final readonly class FinancingRowData
{
    public function __construct(
        public int $fiscalYear,
        public string $amount,
        public string $kind = 'budget',
        public ?string $kbk = null,
        public ?string $kvr = null,
        public ?string $receiptCode = null,
        public bool $isBeyondHorizon = false,
    ) {}
}
