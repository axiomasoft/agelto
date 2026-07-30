<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\TotalKind;
use Spatie\LaravelData\Data;

/**
 * Итоговая строка/начисление сметы (перенос v1 EstimateFooterItem, docs/specs/v2/
 * 08-estimate-extraction.md §1.3/§3.1). Рекурсивная структура — маппится 1:1 в дерево
 * prc_estimate_totals (parent_id/position).
 * - label: подпись строки («Итого», «в том числе НДС», «Накладные расходы»).
 * - value: сумма строкой (decimal без потерь — конверсия во float запрещена); nullable — начисление,
 *   заданное процентом (см. `rate`), может не иметь собственной суммы в документе (P4.5,
 *   `prc_estimate_totals.amount` стала nullable).
 * - kind: машиночитаемая пометка типа начисления — замкнутое множество `TotalKind`
 *   (`overhead`/`profit`/`vat`/`total`/`additional`/`other`, D101), если модель распознаёт по label;
 *   иначе null — детерминированное доопределение по label этим планом не строится (долг, §7
 *   research/06-schema-decision.md).
 * - chapter_number: номер главы сводного расчёта, если начисление привязано к главе (SR-11, P4.5).
 * - rate: ставка начисления в процентах, если задано процентом, а не суммой (SR-11, P4.5) —
 *   признак «задано процентом» — это `rate !== null`, отдельного булева нет.
 * - children: вложенные строки («в том числе»).
 */
final class TotalLine extends Data
{
    /**
     * @param  TotalLine[]  $children
     */
    public function __construct(
        public string $label,
        public ?string $value = null,
        public ?TotalKind $kind = null,
        public ?int $chapter_number = null,
        public ?string $rate = null,
        public array $children = [],
    ) {}
}
