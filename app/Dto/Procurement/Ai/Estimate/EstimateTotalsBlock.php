<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use Spatie\LaravelData\Data;

/**
 * Блок итогов сметы — дерево начислений (docs/specs/v2/08-estimate-extraction.md §1.3/§3.1/§3.3).
 * Один и тот же контракт используется и внутри extract_passport (footer, если документ виден
 * целиком), и как тело отдельного extract_totals-вызова (TotalsResponse) — вызывается отдельно,
 * когда паспорт запрашивался без полного документа и не мог увидеть концевик.
 * - footer: корневые итоговые строки сметы (рекурсивные — см. TotalLine.children).
 */
final class EstimateTotalsBlock extends Data
{
    /**
     * @param  TotalLine[]  $footer
     */
    public function __construct(
        public array $footer = [],
    ) {}
}
