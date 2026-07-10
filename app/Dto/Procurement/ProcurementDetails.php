<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

/**
 * Детальная карточка закупки (вкладки `common-info`/`documents`/`event-journal`/`supplier-results`,
 * `02-crawling.md` §5) — сырой HTML вкладок, снятый `SourceMethod::collectDetails()`. Парсинг
 * вкладок в доменные таблицы — вне этого DTO (tab-процессоры, план V2-CRAWL P3.3–P3.7).
 */
final readonly class ProcurementDetails
{
    public function __construct(
        public string $registryNumber,
        public string $procedureUrlType,
        public ?string $commonInfoHtml = null,
        public ?string $documentsHtml = null,
        public ?string $eventJournalHtml = null,
        public ?string $supplierResultsHtml = null,
    ) {}
}
