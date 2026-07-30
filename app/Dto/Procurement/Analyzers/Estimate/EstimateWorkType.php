<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use Spatie\LaravelData\Data;

/**
 * Вид работ и его иерархия — носитель нормативов НР/СП (SR-08/SR-09,
 * `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §5.5, D34/D90). Маппится 1:1 в
 * `prc_estimate_work_types` (per-estimate, self-FK `parent_id`). `title` — единственное поле,
 * которое заполняет контракт LLM (`work_type_name` на строке позиции, `EstimateRow::$work_type_name`);
 * нормативы и иерархия — ТОЛЬКО детерминированный маппер `.gsfx` (исключение из D33, §6 п.3
 * research/06) — LLM-вызов этот массив никогда не заполняет, он остаётся пустым на боевом потоке.
 * - title: подпись вида работ или группы.
 * - overhead_code / overhead_rate / overhead_rate_curr: шифр и ставка накладных расходов (базисный/
 *   текущий уровень).
 * - profit_code / profit_rate / profit_rate_curr: шифр и ставка сметной прибыли (базисный/текущий
 *   уровень).
 * - children: вложенные виды работ (группа → вид, SR-09).
 */
final class EstimateWorkType extends Data
{
    /**
     * @param  EstimateWorkType[]  $children
     */
    public function __construct(
        public string $title,
        public ?string $overhead_code = null,
        public ?string $overhead_rate = null,
        public ?string $overhead_rate_curr = null,
        public ?string $profit_code = null,
        public ?string $profit_rate = null,
        public ?string $profit_rate_curr = null,
        public array $children = [],
    ) {}
}
