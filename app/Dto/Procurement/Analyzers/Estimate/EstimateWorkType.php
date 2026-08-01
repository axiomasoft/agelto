<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use Spatie\LaravelData\Data;

/**
 * Вид работ и его иерархия — носитель нормативов НР/СП. Маппится 1:1 в `prc_estimate_work_types`
 * (per-estimate, self-FK `parent_id`). `title` — единственное поле, которое заполняет контракт LLM
 * (`work_type_name` на строке позиции, `EstimateRow::$work_type_name`); нормативы и иерархия —
 * ТОЛЬКО детерминированный маппер `.gsfx` — LLM-вызов этот массив никогда не заполняет, он
 * остаётся пустым на боевом потоке.
 */
final class EstimateWorkType extends Data
{
    /**
     * @param  EstimateWorkType[]  $children
     */
    public function __construct(
        #[Description('подпись вида работ или группы.')]
        public string $title,
        #[Description('шифр накладных расходов на базисном уровне.')]
        public ?string $overhead_code = null,
        #[Description('ставка накладных расходов на базисном уровне.')]
        public ?string $overhead_rate = null,
        #[Description('ставка накладных расходов на текущем уровне.')]
        public ?string $overhead_rate_curr = null,
        #[Description('шифр сметной прибыли на базисном уровне.')]
        public ?string $profit_code = null,
        #[Description('ставка сметной прибыли на базисном уровне.')]
        public ?string $profit_rate = null,
        #[Description('ставка сметной прибыли на текущем уровне.')]
        public ?string $profit_rate_curr = null,
        #[Description('вложенные виды работ (группа → вид).')]
        public array $children = [],
    ) {}
}
