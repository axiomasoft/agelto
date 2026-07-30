<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageReport;
use App\Services\Llm\Dto\UsageRow;
use AzGuard\Filament\Concerns\HasAzGuardWidget;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * Первый виджет проекта (P4.3, бриф §3): расход по дням в разрезе провайдера и модели, поверх
 * сейма источника (`root/usage-source-contract.md`). Данные — ТОЛЬКО через `UsageSource::usage()`,
 * прямого обращения к таблице вызовов здесь нет (Implementation Rule 1). Подпись источника/лага/
 * горизонта — обязательный элемент (Rule 2), не отдельный запрос: репорт кэшируется в `report()` и
 * переиспользуется `getData()`/`getDescription()`, чтобы обе точки читали ОДИН ответ источника.
 */
final class LlmSpendChartWidget extends ChartWidget
{
    use HasAzGuardWidget;

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
        $rows = Collection::make($this->report()->rows);

        $labels = $rows
            ->pluck('day')
            ->filter()
            ->map(fn (CarbonImmutable $day): string => $day->toDateString())
            ->unique()
            ->sort()
            ->values();

        $series = $rows->groupBy(
            fn (UsageRow $row): string => sprintf('%s · %s', $row->provider ?? '—', $row->model ?? '—'),
        );

        $datasets = $series
            ->map(function (Collection $seriesRows, string $label) use ($labels): array {
                $costByDay = $seriesRows->keyBy(fn (UsageRow $row): ?string => $row->day?->toDateString());

                return [
                    'label' => $label,
                    'data' => $labels
                        ->map(function (string $day) use ($costByDay): float {
                            $row = $costByDay->get($day);

                            return round(($row === null ? 0 : $row->costMicros) / 1_000_000, 4);
                        })
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'datasets' => $datasets,
            'labels' => $labels->all(),
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
