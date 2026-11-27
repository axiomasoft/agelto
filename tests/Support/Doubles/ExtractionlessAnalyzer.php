<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;
use LogicException;

/**
 * Тип документа, у которого стадии извлечения НЕТ, — чтобы ветка «анализатор есть, извлечения
 * нет» проверялась отдельно от ветки «анализатора нет вовсе»: в ядре они дают один и тот же
 * исход (`screened` без диспатча), и без этого двойника вторая ветка маскировала бы первую.
 *
 * Фиктивный ВТОРОЙ ТИП документа с собственным job'ом — предмет , это не он.
 */
final readonly class ExtractionlessAnalyzer implements DocumentAnalyzer
{
    public function hasExtraction(): bool
    {
        return false;
    }

    public function dispatchExtraction(int $documentId): void
    {
        throw new LogicException('У типа нет стадии извлечения — ядро обязано спросить hasExtraction() раньше.');
    }

    public function promptSteps(): array
    {
        return [];
    }

    public function purposes(): array
    {
        return [];
    }

    public function summarize(Analysis $analysis): AnalysisSummary
    {
        return new AnalysisSummary(
            title: 'Разбора нет',
            metrics: [new SummaryMetric(label: 'Позиции', value: 0)],
        );
    }
}
