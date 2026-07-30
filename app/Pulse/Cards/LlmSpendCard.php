<?php

declare(strict_types=1);

namespace App\Pulse\Cards;

use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Кастомная Pulse-карточка «LLM-расход» (D25, P7.5 Intent п.2, phases/P7.md): токены/стоимость
 * за фиксированное окно 24ч, группировка по `purpose` — оперативный срез рядом с дневной кривой
 * админки (D35). Цифры берутся у источника расхода (`root/usage-source-contract.md`) и несут его
 * подпись; курс ₽ — тот же `config('ai.cost_report.rub_per_usd')`, что и у
 * `App\Services\Llm\LlmSpendReporter` (деньги-производные считает потребитель, D124).
 */
#[Lazy]
final class LlmSpendCard extends Card
{
    public function render(): View
    {
        // Livewire создаёт компонент как `new $class` (`Factory::create()`) и зовёт `render()` без
        // аргументов — ни конструкторной инъекции, ни инъекции в метод у Pulse-карточки быть не
        // может. Резолв из контейнера ЗДЕСЬ — граница фреймворка, а не сервис-локатор из лени.
        $source = app(UsageSource::class);

        $to = CarbonImmutable::now();
        $report = $source->usage(new UsageQuery(from: $to->subDay(), to: $to, byPurpose: true));

        $rows = Collection::make($report->rows);

        return view('livewire.pulse.llm-spend', [
            'rows' => $rows,
            'rubPerUsd' => (float) config('ai.cost_report.rub_per_usd'),
            'totalCostMicros' => (int) $rows->sum('costMicros'),
            'signature' => $report->signature->toLine(),
        ]);
    }
}
