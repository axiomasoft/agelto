<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Enums\Llm\CorePromptStep;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;

/**
 * Двойник, объявляющий ЯДЕРНОЕ значение шага (`CorePromptStep::Global`) как СВОЁ — единственный
 * способ проверить инвариант сборки реестра §5.4 п.2 (значения анализатора не пересекаются с
 * ядерными): без него анализатор со значением `'global'` молча затенил бы ядерный промт.
 */
final readonly class GlobalStepAnalyzer implements DocumentAnalyzer
{
    public function hasExtraction(): bool
    {
        return false;
    }

    public function dispatchExtraction(int $documentId): void {}

    public function promptSteps(): array
    {
        return [CorePromptStep::Global];
    }

    public function purposes(): array
    {
        return [];
    }

    public function summarize(Analysis $analysis): AnalysisSummary
    {
        return new AnalysisSummary(
            title: 'н/д',
            metrics: [new SummaryMetric(label: 'н/д', value: 0)],
        );
    }
}
