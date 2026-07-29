<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\LlmPurpose;
use App\Enums\Procurement\PromptStep;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;

/**
 * Смета как тип документа — первая реализация `DocumentAnalyzer` (§6.1 контракта P2.1:
 * всё, что принадлежит типу, лежит по пути с сегментом `Analyzers\Estimate\` в СВОЁМ слое).
 *
 * На этом шаге анализатор — тонкая обёртка: он объявляет наличие извлечения, свой job, свои шаги
 * и назначения и собирает сводку результата, а внутрь зовёт существующие классы по их
 * СЕГОДНЯШНИМ путям. Переезд шестнадцати сметных единиц и самого job'а в этот каталог — P2.4,
 * расщепление enum'ов шагов/назначений на ядерные и сметные — P2.5.
 */
final readonly class EstimateAnalyzer implements DocumentAnalyzer
{
    public function hasExtraction(): bool
    {
        return true;
    }

    public function dispatchExtraction(int $documentId): void
    {
        EstimateExtractionJob::dispatch($documentId)->afterCommit();
    }

    /**
     * @return list<PromptStep>
     */
    public function promptSteps(): array
    {
        return [
            PromptStep::ExtractionGlobal,
            PromptStep::ExtractionDiscovery,
            PromptStep::ExtractionPassport,
            PromptStep::ExtractionRows,
            PromptStep::ExtractionTotals,
        ];
    }

    /**
     * @return list<LlmPurpose>
     */
    public function purposes(): array
    {
        return [
            LlmPurpose::Discovery,
            LlmPurpose::ExtractPassport,
            LlmPurpose::ExtractRows,
            LlmPurpose::ExtractTotals,
        ];
    }

    /**
     * Сводка прогона извлечения: сколько смет, сколько позиций и на какую сумму. Деньги считаются
     * `bcadd` по строковому представлению `decimal:2` — прогон через `float` терял бы копейки
     * ровно там, где их показывают (§3 контракта). Предметного экрана у сметы пока нет, поэтому
     * `detailRoute` — `null`: маршрут появится вместе с витриной P5.
     */
    public function summarize(Analysis $analysis): AnalysisSummary
    {
        $estimates = $analysis->estimates()->orderBy('ordinal')->get();

        $rows = 0;
        $total = '0.00';
        $currency = null;

        foreach ($estimates as $estimate) {
            $rows += (int) ($estimate->rows_extracted ?? 0);
            $total = bcadd($total, (string) ($estimate->total_amount ?? '0'), 2);
            // Форма `?? null` — не украшение: вне `??`-контекста Larastan не резолвит тип
            // свойства модели и заводит `property.notFound` (тот же приём, что P2.2).
            $currency ??= $estimate->currency ?? null;
        }

        $count = $estimates->count();

        return new AnalysisSummary(
            title: $this->plural($count, 'смета', 'сметы', 'смет').', '.$this->plural($rows, 'позиция', 'позиции', 'позиций'),
            metrics: [
                new SummaryMetric(label: 'Сметы', value: $count),
                new SummaryMetric(label: 'Позиции', value: $rows),
                new SummaryMetric(label: 'Сумма', value: $total, unit: $currency),
            ],
        );
    }

    /**
     * Русская форма числительного — «1 смета / 2 сметы / 5 смет». Витрине отдаётся готовая
     * подпись, потому что склонение зависит от СЛОВА, а не от числа: разбирать его в презентации
     * значило бы вернуть туда знание о предмете.
     */
    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        $form = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };

        return "{$count} {$form}";
    }
}
