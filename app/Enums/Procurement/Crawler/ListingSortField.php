<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Поле сортировки выдачи листинга ЕИС — доменный смысл, не имя параметра площадки:
 * маппинг на `sortBy` (`UPDATE_DATE`/`PUBLISH_DATE`) живёт в `EisCsvUrlBuilder`.
 */
enum ListingSortField: string implements HasLabel
{
    case Published = 'published';
    case Updated = 'updated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Published => 'По дате публикации',
            self::Updated => 'По дате обновления',
        };
    }
}
