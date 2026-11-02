<?php

declare(strict_types=1);

namespace Tests\Support\Analyzer;

use App\Exceptions\Llm\UnsupportedConsumer;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\Dto\AnalysisSummary;
use App\Services\Procurement\Contracts\Dto\SummaryMetric;

/**
 * Фиктивный ВТОРОЙ тип документа (P2.6, D14/D26) — доказательство расширяемости: тип со СВОИМ
 * job'ом извлечения, зарегистрированный ОДНОЙ строкой `config('ai.analyzers')` только в тестовом
 * окружении (Implementation Rules 2–3). Использует существующий кейс `AiDocumentType::TechSpec` —
 * заводить кейс ради теста запрещено (Implementation Rule 3).
 *
 * НЕ реализует `SupportsResultComparison` (§1.5 контракта, D106) — намеренно: тип без опциональной
 * способности обязан давать штатный `null` в `ShadowAnalysisJob::compareWithPrimary()`, а не
 * ошибку; `EstimateAnalyzer` — сегодня единственный реализующий эту способность тип, и ветку
 * «способности нет» им проверить нельзя.
 */
final readonly class TechSpecFakeAnalyzer implements DocumentAnalyzer
{
    public function hasExtraction(): bool
    {
        return true;
    }

    public function dispatchExtraction(int $documentId): void
    {
        throw new UnsupportedConsumer;
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
            title: 'Фиктивный второй тип',
            metrics: [new SummaryMetric(label: 'Позиции', value: 0)],
        );
    }
}
