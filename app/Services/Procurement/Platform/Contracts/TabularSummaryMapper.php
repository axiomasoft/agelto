<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Extraction\MappedTabularSummary;
use App\Dto\Procurement\Parsing\Rules\ListingColumnsRule;

/**
 * Контракт сопоставления табличных колонок и строк по декларативным правилам.
 */
interface TabularSummaryMapper
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return array{items: list<array<string, string|null>>, summary: MappedTabularSummary}
     */
    public function map(array $headers, array $rows, ListingColumnsRule $rule): array;
}
