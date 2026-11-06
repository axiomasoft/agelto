<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Estimate\Estimate;
use App\Repositories\Procurement\Ai\DatasetExampleStoreRepository;

/**
 * Снимок нормализованных смет для gold; dataset writer владеет версиями и analysis identity.
 * Source=corrected только при applied correction прямого предшественника, иначе llm.
 * ReviewAnalysisAction атомарно сохраняет операторское решение вместе со снимком.
 */
final readonly class GoldExampleRecorder
{
    public function __construct(
        private EstimateToContractMapper $mapper,
        private DatasetExampleStoreRepository $examples,
    ) {}

    /**
     * Скрининг (`AnalysisStep::Screening`) не производит `estimates[]`-контракт (`ScreeningResult`
     * — другая форма ответа) — gold-петля этой стратегии покрывает только извлечение смет; остальные шаги не создают gold этой формы.
     *
     * Dataset writer сериализует версии на document_version и переиспользует snapshot этого
     * analysis_id. Атомарность review + gold принадлежит ReviewAnalysisAction.
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

        $this->examples->recordAnalysisSnapshot(
            analysis: $analysis,
            gold: [
                'tier' => 1,
                'estimates' => $estimates
                    ->map(fn (Estimate $estimate): array => $this->mapper->toContract($estimate))
                    ->all(),
            ],
            source: $this->wasCorrected($analysis) ? 'corrected' : 'llm',
        );
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
}
