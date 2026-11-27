<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\TotalKind;
use Spatie\LaravelData\Data;

/**
 * Итоговая строка/начисление сметы (перенос v1 EstimateFooterItem, docs/specs/v2/
 * 08-estimate-extraction.md §1.3/§3.1). Рекурсивная структура — маппится 1:1 в дерево
 * prc_estimate_totals (parent_id/position).
 */
final class TotalLine extends Data
{
    /**
     * @param  TotalLine[]  $children
     */
    public function __construct(
        #[Description('подпись строки («Итого», «в том числе НДС», «Накладные расходы»).')]
        public string $label,
        #[Description('сумма строкой (decimal без потерь — конверсия во float запрещена); nullable — начисление, заданное процентом (см. rate), может не иметь собственной суммы в документе.')]
        public ?string $value = null,
        #[Description('машиночитаемая пометка типа начисления, если модель распознаёт по label; иначе null — детерминированное доопределение по label этим планом не строится (долг).')]
        public ?TotalKind $kind = null,
        #[Description('номер главы сводного расчёта, если начисление привязано к главе.')]
        public ?int $chapter_number = null,
        #[Description('ставка начисления в процентах, если задано процентом, а не суммой — признак «задано процентом» — это rate !== null, отдельного булева нет.')]
        public ?string $rate = null,
        #[Description('вложенные строки («в том числе»).')]
        public array $children = [],
    ) {}
}
