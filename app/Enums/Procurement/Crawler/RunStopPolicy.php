<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Политика остановки fresh-прогона: `Default` —
 * только блок без изменений/повтор `dedupSignature`; `OnlyFresh` — дополнительно время старта
 * предыдущего завершённого прогона (с запасом из `crawler.fresh.only_fresh_margin_minutes`).
 * Легальна только при `Crawler::mode === CrawlerMode::Fresh` (гейт `Crawler::saving()`).
 */
enum RunStopPolicy: string implements HasLabel
{
    case Default = 'default';
    case OnlyFresh = 'only_fresh';

    public function getLabel(): string
    {
        return match ($this) {
            self::Default => 'Стандартная',
            self::OnlyFresh => 'Только новые',
        };
    }
}
