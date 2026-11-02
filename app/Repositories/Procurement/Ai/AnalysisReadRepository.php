<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

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

    public function lastForRun(string $runId): ?Analysis
    {
        return Analysis::query()->where('analysis_run_id', $runId)->orderByDesc('id')->first();
    }
}
