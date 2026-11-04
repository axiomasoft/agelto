<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Маркер границы сметы в discovery-окне. Backend склеивает маркеры окон в сегменты
 * {estimate_ordinal, page_start, page_end}; модель не задаёт итоговую сегментацию.
 * EstimateStart: первая страница сметы. Continuation: продолжение. EstimateEnd: последняя страница.
 */
enum DiscoveryMarker: string
{
    case EstimateStart = 'estimate_start';
    case Continuation = 'continuation';
    case EstimateEnd = 'estimate_end';
}
