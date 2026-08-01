<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use Spatie\LaravelData\Data;

/**
 * Блок итогов сметы — дерево начислений (docs/specs/v2/08-estimate-extraction.md §1.3/§3.1/§3.3).
 * Один и тот же контракт используется и внутри extract_passport (footer, если документ виден
 * целиком), и как тело отдельного extract_totals-вызова (TotalsResponse) — вызывается отдельно,
 * когда паспорт запрашивался без полного документа и не мог увидеть концевик.
 */
final class EstimateTotalsBlock extends Data
{
    /**
     * @param  TotalLine[]  $footer
     */
    public function __construct(
        #[Description('корневые итоговые строки сметы (рекурсивные — см. TotalLine.children).')]
        public array $footer = [],
    ) {}
}
