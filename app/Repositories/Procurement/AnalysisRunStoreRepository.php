<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\Ai\AnalysisRunStatus;
use App\Enums\Procurement\Ai\RunMode;
use App\Models\Procurement\Ai\AnalysisRun;

/**
 * Единственная точка персистентности `prc_ai_analysis_runs` (§3 root/document-contour.md,
 * аналог `AnalysisStoreRepository`) — launch-точки (`PrepareDocumentJob`, `PipelineRetryFailed`,
 * `RerunAnalysisAction`, `FormatBenchRunner`, `EvalRunCommand`) решают, когда завести прогон и
 * каким `RunMode`, репозиторий только пишет.
 */
final readonly class AnalysisRunStoreRepository
{
    /**
     * Заводит прогон СРАЗУ в `running` (Implementation Rule 10, P1.6): сегодняшний код не несёт
     * отдельного «в очереди, но ещё не подхвачен» состояния ни на одной стадии пайплайна
     * (`DocumentStatus` его тоже не заводит) — `pending` остаётся легальным значением
     * check-constraint'а для будущего асинхронного launch-пути, но эта точка его не производит.
     */
    public function start(int $documentVersionId, RunMode $mode, ?int $initiatedBy = null): AnalysisRun
    {
        return AnalysisRun::query()->create([
            'document_version_id' => $documentVersionId,
            'mode' => $mode,
            'status' => AnalysisRunStatus::Running,
            'initiated_by' => $initiatedBy,
            'started_at' => now(),
        ]);
    }

    /**
     * Активный (незавершённый) прогон версии — резолв для `AnalysisStoreRepository::firstOrCreate()`/
     * `createRerun()` (§3.5). Единственность гарантирует partial unique
     * `prc_ai_analysis_runs_active_version_unique` — `first()` без доп. `ORDER BY` корректен.
     */
    public function findActive(int $documentVersionId): ?AnalysisRun
    {
        return AnalysisRun::query()
            ->where('document_version_id', $documentVersionId)
            ->whereNull('finished_at')
            ->first();
    }

    /**
     * Терминация `succeeded` — И финальный исход, И «шаг пропущен» (`AnalysisStatus::Skipped`,
     * §3.2: прогон, дошедший до unprocessable-шага, отработал штатно). Идемпотентна: уже
     * завершённый прогон вторым вызовом не трогается (двойной вызов из независимых call-site'ов
     * безопасен).
     */
    public function markSucceeded(AnalysisRun $run): void
    {
        $this->terminate($run, AnalysisRunStatus::Succeeded);
    }

    public function markFailed(AnalysisRun $run): void
    {
        $this->terminate($run, AnalysisRunStatus::Failed);
    }

    private function terminate(AnalysisRun $run, AnalysisRunStatus $status): void
    {
        if ($run->finished_at !== null) {
            return;
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
        ]);
    }

    /**
     * Запись слепка расхода прогона (P4.1) — чистая персистентность, вычисление суммы остаётся
     * за вызывающим (`UsageSnapshotRecorder`, `AnalysisReadRepository::usageSummaryForRun()`).
     * Не привязана к терминации: теневой анализ может доиграть ПОСЛЕ того, как прогон уже
     * `finished_at`, и его вклад в слепок пересчитывается этим же вызовом (пересчёт, не инкремент,
     * Implementation Rule 5) — статус/`finished_at` при этом не трогаются.
     *
     * @param  array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int, cost_micros: int}  $usage
     */
    public function recordUsage(AnalysisRun $run, array $usage): void
    {
        $run->update($usage);
    }
}
