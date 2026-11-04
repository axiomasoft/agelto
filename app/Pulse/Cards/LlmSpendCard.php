<?php

declare(strict_types=1);

namespace App\Pulse\Cards;

use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Pulse-карточка токенов и стоимости за последние 24 часа с группировкой по `purpose`.
 * Читает {@see UsageSource} и показывает подпись источника. Пересчёт в рубли использует
 * `config('ai.cost_report.rub_per_usd')`, как и `LlmSpendReporter`.
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

        /** @var Collection<int, UsageRow> $rows */
        $rows = Collection::make($report->rows);

        return view('livewire.pulse.llm-spend', [
            'rows' => $rows,
            'rubPerUsd' => $this->rubPerUsd(),
            'totalCostMicros' => $rows->reduce(
                static fn (int $total, UsageRow $row): int => $total + $row->costMicros,
                initial: 0,
            ),
            'signature' => $report->signature->toLine(),
        ]);
    }

    private function rubPerUsd(): float
    {
        $configured = config('ai.cost_report.rub_per_usd');

        return is_int($configured) || is_float($configured) || is_string($configured)
            ? (float) $configured
            : 0.0;
    }
}
