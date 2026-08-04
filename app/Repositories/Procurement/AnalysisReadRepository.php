<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;

/**
 * Чтение `prc_ai_analyses` (`root/service-layer-map.md` §6), рядом с `AnalysisStoreRepository`.
 */
final readonly class AnalysisReadRepository
{
    public function find(int $id): ?Analysis
    {
        return Analysis::query()->find($id);
    }

    public function findByIdAndStep(int $id, AnalysisStep $step): ?Analysis
    {
        return Analysis::query()->where('step', $step)->find($id);
    }

    public function activeForVersion(int $documentVersionId, AnalysisStep $step): ?Analysis
    {
        return Analysis::query()
            ->active()
            ->where('document_version_id', $documentVersionId)
            ->where('step', $step)
            ->first();
    }

    public function parentOf(Analysis $analysis): ?Analysis
    {
        return $analysis->parent_analysis_id !== null
            ? Analysis::query()->find($analysis->parent_analysis_id)
            : null;
    }

    /**
     * Слепок прогона (P4.1, §3.6 root/document-contour.md) — сумма УЖЕ ПЕРСИСТИРОВАННЫХ слепков
     * его анализов (каждый исключает вызовы тюнинга на своей стороне, D39), а не второй агрегат
     * по `llm_calls`: включает скрининг и теневые анализы естественно — они несут тот же
     * `analysis_run_id` (`createShadow()`), суперпозиции не нужно.
     *
     * @return array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int, cost_micros: int}
     */
    public function usageSummaryForRun(int $analysisRunId): array
    {
        /** @var object{prompt_tokens: numeric-string, completion_tokens: numeric-string, cached_tokens: numeric-string, total_tokens: numeric-string, cost_micros: numeric-string} $row */
        $row = Analysis::query()
            ->where('analysis_run_id', $analysisRunId)
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('coalesce(sum(completion_tokens), 0) as completion_tokens')
            ->selectRaw('coalesce(sum(cached_tokens), 0) as cached_tokens')
            ->selectRaw('coalesce(sum(total_tokens), 0) as total_tokens')
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost_micros')
            ->first();

        return [
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'cached_tokens' => (int) $row->cached_tokens,
            'total_tokens' => (int) $row->total_tokens,
            'cost_micros' => (int) $row->cost_micros,
        ];
    }
}
