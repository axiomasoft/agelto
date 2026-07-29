<?php

declare(strict_types=1);

namespace App\Pulse\Cards;

use App\Models\Llm\LlmCall;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Кастомная Pulse-карточка «LLM-расход» (D25, P7.5 Intent п.2, phases/P7.md): токены/стоимость
 * по `llm_calls` за фиксированное окно 24ч, группировка по `purpose` — прямой SQL-агрегат
 * (Code Guidance: НЕ дублировать в pulse-таблицы). Прайсинг переиспользует ту же формулу, что
 * `App\Console\Commands\Ai\CostReportCommand` (P7.2, `config('ai.cost_report.rub_per_usd')`).
 */
#[Lazy]
final class LlmSpendCard extends Card
{
    public function render(): View
    {
        $since = CarbonImmutable::now()->subDay();

        $rows = LlmCall::query()
            ->where('called_at', '>=', $since)
            ->toBase()
            ->selectRaw('purpose')
            ->selectRaw('count(*) as calls')
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('coalesce(sum(cached_tokens), 0) as cached_tokens')
            ->selectRaw('coalesce(sum(completion_tokens), 0) as completion_tokens')
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost_micros')
            ->groupBy('purpose')
            ->orderBy('purpose')
            ->get();

        return view('livewire.pulse.llm-spend', [
            'rows' => $rows,
            'rubPerUsd' => (float) config('ai.cost_report.rub_per_usd'),
            'totalCostMicros' => (int) $rows->sum('cost_micros'),
        ]);
    }
}
