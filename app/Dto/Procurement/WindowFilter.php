<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

use Carbon\CarbonInterface;

/**
 * Агностичное описание одного date-окна для `ArchiveWindowScanner::scan()`
 * (`02-crawling.md` §4.2): P4.1 гоняет по дням публикации (`dateField=publish`,
 * `dateFrom===dateTo`), P4.2 переиспользует по окну обновления (`dateField=update`).
 */
final readonly class WindowFilter
{
    /**
     * @param  'publish'|'update'  $dateField
     */
    public function __construct(
        public string $dateField,
        public CarbonInterface $dateFrom,
        public CarbonInterface $dateTo,
    ) {}
}
