<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Маркер границы сметы внутри окна discovery-прохода (docs/specs/v2/08-estimate-extraction.md §3.4,
 * D30/D37). Бэкенд детерминированно склеивает маркеры всех окон в сегменты
 * {estimate_ordinal, page_start, page_end} — сама сегментация не решение LLM.
 * - EstimateStart: первая страница новой сметы в окне.
 * - Continuation: страница — продолжение текущей сметы, не начало и не конец.
 * - EstimateEnd: последняя страница текущей сметы в окне.
 */
enum DiscoveryMarker: string
{
    case EstimateStart = 'estimate_start';
    case Continuation = 'continuation';
    case EstimateEnd = 'estimate_end';
}
