<?php

declare(strict_types=1);

namespace App\Dto\Crawler;

/**
 * Детальная карточка закупки (вкладки `common-info`/`documents`/`event-journal`/`supplier-results`,
 * `02-crawling.md` §5) — плейсхолдер-контракт для `SourceMethod::collectDetails()`. Поля вкладок
 * наполняются в плане V2-CRAWL, фаза P3; здесь фиксируется только точка расширения.
 */
final readonly class ProcurementDetails {}
