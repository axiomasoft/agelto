<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Адрес сметы в многосметном документе вычисляется `Estimate::sourceKind()`
 * из `source_sheet_name` и `source_page_start`, отдельной колонки не имеет.
 * WholeDocument: документ несёт одну смету целиком.
 * Pages: адрес задаётся диапазоном страниц.
 * Sheet: адрес задаётся листом книги.
 */
enum EstimateSourceKind: string
{
    case WholeDocument = 'whole_document';
    case Pages = 'pages';
    case Sheet = 'sheet';
}
