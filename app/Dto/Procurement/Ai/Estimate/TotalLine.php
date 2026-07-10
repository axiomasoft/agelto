<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use Spatie\LaravelData\Data;

/**
 * Итоговая строка/начисление сметы (перенос v1 EstimateFooterItem, docs/specs/v2/
 * 08-estimate-extraction.md §1.3/§3.1). Рекурсивная структура — маппится 1:1 в дерево
 * prc_estimate_totals (parent_id/position).
 * - label: подпись строки («Итого», «в том числе НДС», «Накладные расходы»).
 * - value: сумма строкой (decimal без потерь — конверсия во float запрещена).
 * - kind: машиночитаемая пометка типа начисления (НР/СП/НДС/…), если модель распознаёт по label;
 *   иначе null — детерминированно доопределяется на бэкенде по label.
 * - children: вложенные строки («в том числе»).
 */
final class TotalLine extends Data
{
    /**
     * @param  TotalLine[]  $children
     */
    public function __construct(
        public string $label,
        public string $value,
        public ?string $kind = null,
        public array $children = [],
    ) {}
}
