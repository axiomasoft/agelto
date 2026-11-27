<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Dto\Llm\Reporting\UsageQuery;
use App\Dto\Llm\Reporting\UsageReport;
use App\Dto\Llm\Reporting\UsageRow;
use App\Services\Llm\Contracts\UsageSource;
use AzGuard\Filament\Concerns\AuthorizesWidget;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * Дневной расход по провайдеру и модели через `UsageSource::usage()`. Подпись источника,
 * lag и горизонт берутся из того же report, что данные графика. `report()` кэширует ответ
 * для согласованного чтения в `getData()` и `getDescription()`.
 */
final class LlmSpendChartWidget extends ChartWidget
{
    use AuthorizesWidget;

    protected ?string $heading = 'Расход LLM по дням';

    public ?string $filter = '30';

    private ?UsageReport $cachedReport = null;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): array
    {
        return [
            '7' => '7 дней',
            '30' => '30 дней',
            '90' => '90 дней',
        ];
    }

    public function getDescription(): string
    {
        $report = $this->report();

        $description = $report->signature->toLine();

        if ($report->beyondHorizon) {
            $description .= ' · часть периода вне горизонта — данных нет, партиции старше retention удалены';
        }

        return $description;
    }

    /**
     * @return array{datasets: list<array{label: string, data: list<float>}>, labels: list<string>}
     */
    protected function getData(): array
    {
        /** @var Collection<int, UsageRow> $rows */
        $rows = collect($this->report()->rows);

        /** @var list<string> $labels */
        $labels = array_values($rows
            ->pluck('day')
            ->filter()
            ->map(static fn (mixed $day): string => $day instanceof CarbonImmutable ? $day->toDateString() : '')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all());

        $series = $rows->groupBy(
            fn (UsageRow $row): string => sprintf('%s · %s', $row->provider ?? '—', $row->model ?? '—'),
        );

        /** @var list<array{label: string, data: list<float>}> $datasets */
        $datasets = [];

        foreach ($series as $label => $seriesRows) {
            if (! is_string($label)) {
                continue;
            }

            /** @var Collection<int, UsageRow> $seriesRows */
            $costByDay = [];
            foreach ($seriesRows as $row) {
                if ($row->day !== null) {
                    $costByDay[$row->day->toDateString()] = $row;
                }
            }

            $data = [];
            foreach ($labels as $day) {
                $row = $costByDay[$day] ?? null;
                $data[] = round(($row === null ? 0 : $row->costMicros) / 1_000_000, 4);
            }

            $datasets[] = ['label' => $label, 'data' => $data];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    private function report(): UsageReport
    {
        return $this->cachedReport ??= app(UsageSource::class)->usage($this->query());
    }

    private function query(): UsageQuery
    {
        $to = CarbonImmutable::now();
        $days = (int) ($this->filter ?? '30');

        return new UsageQuery(
            from: $to->subDays($days),
            to: $to,
            byDay: true,
            byProvider: true,
            byModel: true,
        );
    }
}
