<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Enums\Procurement\PromptStep;
use App\Models\Llm\Prompt;
use Illuminate\Support\Collection;

/**
 * Чтение активных промтов для композиции системной инструкции (`PromptComposer`, ТЗ 04 §3.2,
 * порядок v1 — `Old/app/Repositories/Prompt/PromptRuleRepository.php`).
 */
final readonly class PromptReadRepository
{
    /**
     * Активные промты для набора шагов, по одному на `key` (у key берётся максимальная version
     * среди активных, Implementation Rules), в порядке композиции: индекс шага в `$steps`
     * (global → step-global → step), затем `priority` ASC.
     *
     * @param  list<PromptStep>  $steps  Порядок задаёт композицию — первый элемент раньше в системной части.
     * @return Collection<int, Prompt>
     */
    public function activeForSteps(array $steps): Collection
    {
        $stepValues = array_map(fn (PromptStep $step): string => $step->value, $steps);

        $latestPerKey = Prompt::query()
            ->whereIn('step', $stepValues)
            ->where('is_active', true)
            ->get()
            ->groupBy('key')
            ->map(fn (Collection $group): Prompt => $group->sortByDesc('version')->first());

        return $latestPerKey
            ->sortBy([
                fn (Prompt $a, Prompt $b): int => array_search($a->step, $stepValues, true) <=> array_search($b->step, $stepValues, true),
                fn (Prompt $a, Prompt $b): int => $a->priority <=> $b->priority,
            ])
            ->values();
    }
}
