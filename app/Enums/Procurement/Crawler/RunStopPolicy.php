<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Политика остановки fresh-прогона (P3.3, `research/04-p3-runs-design.md` §4): `Default` —
 * только блок без изменений/повтор `dedupSignature`; `OnlyFresh` — дополнительно время старта
 * предыдущего завершённого прогона (с запасом из `crawler.fresh.only_fresh_margin_minutes`).
 * Легальна только при `Crawler::mode === CrawlerMode::Fresh` (гейт `Crawler::saving()`).
 */
enum RunStopPolicy: string
{
    case Default = 'default';
    case OnlyFresh = 'only_fresh';
}
