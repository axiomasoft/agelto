<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use Carbon\CarbonInterface;

/**
 * Агностичное описание одного date-окна для `ArchiveWindowScanner::scan()`:
 * дни публикации (`dateField=publish`, `dateFrom===dateTo`) или окно обновления
 * (`dateField=update`).
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
