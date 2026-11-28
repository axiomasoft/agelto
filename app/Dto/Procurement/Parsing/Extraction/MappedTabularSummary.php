<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Extraction;

/**
 * Сводка разбора табличного источника по настроенным правилам маппинга колонок.
 */
final readonly class MappedTabularSummary
{
    /**
     * @param  list<string>  $unmatchedHeaders
     * @param  list<string>  $missingRequiredColumns
     */
    public function __construct(
        public int $totalRows,
        public int $mappedRows,
        public int $skippedRows,
        public array $unmatchedHeaders = [],
        public array $missingRequiredColumns = [],
    ) {}
}
