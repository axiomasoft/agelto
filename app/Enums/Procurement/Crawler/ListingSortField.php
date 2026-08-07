<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

/**
 * Поле сортировки выдачи листинга ЕИС — доменный смысл, не имя параметра площадки:
 * маппинг на `sortBy` (`UPDATE_DATE`/`PUBLISH_DATE`) живёт в `EisCsvUrlBuilder`
 * (Code Guidance P3.1).
 */
enum ListingSortField: string
{
    case Published = 'published';
    case Updated = 'updated';
}
