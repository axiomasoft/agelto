<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Estimate\Estimate;

/**
 * Единственный потребитель `review_status=approved` («Проверено» → ). Снимает
 * immutable-снапшот `prc_ai_dataset_examples` из НОРМАЛИЗОВАННЫХ смет анализа
 * (`EstimateToContractMapper`) — повторное approve после re-run (у анализа появляется
 * `parent_analysis_id`) даёт НОВУЮ версию примера, старая строка НЕ мутируется (research/G §4:
 * immutable bundle).
 *
 * `source`: `corrected`, если анализ — re-run, у ПРЯМОГО предшественника которого есть хотя бы
 * одна применённая коррекция (`applied_at` не null — `RerunAnalysisAction`, погашает
 * коррекции ДО диспатча нового job'а); иначе `llm` (approve сырого вывода без цикла правок,
 * включая обычный, не-re-run прогон).
 *
 * DPO-пары (research/G §4, докблок `Analysis`) — chosen выводится ИЗ ЭТОГО снапшота, rejected —
 * из `Analysis.result` анализа-предшественника; собираются запросом на экспорте
 * (`DatasetExportCommand`), отдельная preference-таблица НЕ заводится (решение зафиксировано в
 * докблоке `Analysis`).
 */
final readonly class GoldExampleRecorder
{
    public function __construct(
        private EstimateToContractMapper $mapper,
    ) {}

    /**
     * Скрининг (`AnalysisStep::Screening`) не производит `estimates[]`-контракт (`ScreeningResult`
     * — другая форма ответа) — gold-петля этой стратегии покрывает только извлечение смет; остальные шаги не создают gold этой формы.
     *
     * Идемпотентность повторного вызова обеспечивается ЕДИНСТВЕННЫМ вызывающим
     * (`ViewAnalysis::approveAction`) — action вызывает `record` только при переходе
     * `review_status` в `approved` (guard на `!==Approved` и в `visible`, и в самом action), а не
     * здесь: `prc_ai_dataset_examples` не хранит `analysis_id` (докблок класса ниже), поэтому
     * «уже есть gold для ЭТОГО анализа» нечем проверить на уровне этой таблицы без схемной правки.
     */
    public function record(Analysis $analysis): void
    {
        if ($analysis->step !== AnalysisStep::Extraction) {
            return;
        }

        $estimates = $analysis->estimates()
            ->with(['sections', 'items.resources', 'totals'])
            ->orderBy('ordinal')
            ->get();

        if ($estimates->isEmpty()) {
            return;
        }

        DatasetExample::query()->create([
            'document_version_id' => $analysis->document_version_id,
            'analysis_id' => $analysis->id,
            // Извлечение сегодня — исключительно про сметы (второй тип не реализован);
            // тот же fallback, что `ExtractionOrchestrator`/`RerunRangeAction` уже применяют
            // к `$analysis->document_type` при разборе смет вне явного скрининга.
            'document_type' => $analysis->document_type ?? AiDocumentType::Estimate->value,
            'task' => AnalysisStep::Extraction->value,
            'gold' => [
                'tier' => 1,
                'estimates' => $estimates
                    ->map(fn (Estimate $estimate): array => $this->mapper->toContract($estimate))
                    ->all(),
            ],
            'gold_ref' => null,
            'source' => $this->wasCorrected($analysis) ? 'corrected' : 'llm',
            'verified_by' => $analysis->reviewed_by,
            'verified_at' => $analysis->reviewed_at,
            'version' => $this->nextVersion($analysis->document_version_id),
        ]);
    }

    private function wasCorrected(Analysis $analysis): bool
    {
        if ($analysis->parent_analysis_id === null) {
            return false;
        }

        return Correction::query()
            ->where('analysis_id', $analysis->parent_analysis_id)
            ->whereNotNull('applied_at')
            ->exists();
    }

    private function nextVersion(int $documentVersionId): int
    {
        $maxVersion = DatasetExample::query()
            ->where('document_version_id', $documentVersionId)
            ->where('task', AnalysisStep::Extraction->value)
            ->max('version');

        return (is_int($maxVersion) || is_float($maxVersion) || is_string($maxVersion) ? (int) $maxVersion : 0) + 1;
    }
}
