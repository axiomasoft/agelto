<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extract_totals (docs/specs/v2/08-estimate-extraction.md §3.3). Тот же
 * контракт footer, что в PassportResponse.totals — вызывается отдельно, когда паспорт
 * запрашивался без полного документа и не мог увидеть концевик, либо когда паспорт-вызов
 * намеренно экономит токены и не возвращает footer вовсе.
 */
final class TotalsResponse extends Data
{
    public function __construct(
        #[Description('итоги/начисления сметы (дерево, см. EstimateTotalsBlock).')]
        public EstimateTotalsBlock $totals,
    ) {}
}
