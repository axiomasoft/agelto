<?php

declare(strict_types=1);

namespace App\Jobs\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Jobs\Procurement\PipelineJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Analyzers\Estimate\ExtractionOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use RuntimeException;
use Throwable;

/**
 * Стадия полного извлечения смет (ТЗ 03 §3.5, P6.1): `screened → extracted` на
 * `Ai\Analysis(step=extraction)`. Checkpoint-requeue (D31/D39) — цепочка коротких запусков:
 * `ShouldBeUniqueUntilProcessing` (НЕ `ShouldBeUnique`), т.к. этот job сам себя ре-диспатчит при
 * превышении M диапазонов/T секунд (`ExtractionOrchestrator::run()` возвращает признак
 * «нужно продолжение», `process()` его запоминает) — под обычным `ShouldBeUnique` self-dispatch
 * тихо дедуплицировался бы, пока текущий запуск ещё держит lock (документ навсегда «в
 * extraction», D39). Диспатч продолжения — `afterRelease()` (release-then-dispatch, review-фикс
 * P6.1): СТРОГО после `releaseLease()`, не внутри `process()` — иначе конкурентный воркер,
 * забравший продолжение раньше снятия лиза, проваливает `acquireLease()` и молча становится
 * no-op. Заменяет P5.2-заглушку (`Log::info`) целиком.
 */
final class EstimateExtractionJob extends PipelineJob implements ShouldBeUniqueUntilProcessing
{
    private ?Document $documentCache = null;

    private ?Analysis $analysisCache = null;

    private bool $needsContinuation = false;

    public function __construct(
        public readonly int $documentId,
    ) {
        $this->onQueue('ai_extraction');
    }

    public function uniqueId(): string
    {
        return $this->pipelineUniqueId();
    }

    public function pipelineUniqueId(): string
    {
        return "{$this->documentId}:extraction";
    }

    /**
     * `Document::status` остаётся `screened` на протяжении ВСЕГО многошагового извлечения
     * (переход в `extracted`/`failed`/`unprocessable` — только в терминале, `ExtractionOrchestrator`)
     * — guard пропускает повторные запуски (self-requeue, backlog-поллер, ручной ретрай), пока
     * документ `screened` И анализ ещё не доведён до терминала (`succeeded`/`skipped` — стадия
     * завершена; `failed` — попытки исчерпаны). Тот же приём, что `ScreeningJob` (P5.1/P5.2).
     */
    protected function statusGuardPassed(): bool
    {
        if ($this->document()->status !== DocumentStatus::Screened) {
            return false;
        }

        $version = app(DocumentStoreRepository::class)->latestVersion($this->documentId);

        if ($version === null) {
            return false;
        }

        // `active()` (P9.1/P9.2, review-фикс — тот же приём, что `ScreeningJob`): БЕЗ этого скоупа
        // `->first()` без `ORDER BY` мог вернуть superseded-запись после `RerunAnalysisAction`
        // (P9.2) — терминальный статус старого прогона молча блокировал бы передиспатч job'а.
        $existing = Analysis::query()
            ->active()
            ->where('document_version_id', $version->id)
            ->where('step', AnalysisStep::Extraction)
            ->first();

        return $existing === null || in_array($existing->status, [AnalysisStatus::Pending, AnalysisStatus::Running], true);
    }

    protected function acquireLease(): bool
    {
        return $this->analysis()->acquireLease(owner: self::class);
    }

    protected function releaseLease(): void
    {
        $this->analysis()->releaseLease();
    }

    protected function recordFailure(Throwable $exception): void
    {
        $analysis = $this->analysis();
        $analysis->recordFailure($exception->getMessage());

        if ($analysis->failed_permanently) {
            // `finished_at` (P4.1, Implementation Rule 2): раньше не проставлялся — тот же фикс,
            // что `ScreeningJob::recordFailure()`.
            app(UsageSnapshotRecorder::class)->finalizeAnalysis($analysis, [
                'status' => AnalysisStatus::Failed,
                'finished_at' => now(),
            ]);
            app(DocumentStoreRepository::class)->update($this->document(), ['status' => DocumentStatus::Failed]);
        }
    }

    protected function process(): void
    {
        $this->needsContinuation = app(ExtractionOrchestrator::class)->run($this->document(), $this->analysis());
    }

    /**
     * Release-then-dispatch (D39, review-фикс): self-dispatch продолжения — СТРОГО после
     * `releaseLease()` в `PipelineJob::handle()`, не внутри `process()`. Раньше диспатч уходил,
     * пока текущий запуск ещё держал лиз `Analysis` — конкурентный воркер, забравший продолжение,
     * успевал пройти `statusGuardPassed()`, проваливал `acquireLease()` и молча становился no-op
     * (документ навсегда застревал в extraction).
     */
    protected function afterRelease(): void
    {
        if ($this->needsContinuation) {
            self::dispatch($this->documentId)->afterCommit();
        }
    }

    private function document(): Document
    {
        return $this->documentCache ??= Document::findOrFail($this->documentId);
    }

    private function analysis(): Analysis
    {
        if ($this->analysisCache !== null) {
            return $this->analysisCache;
        }

        $version = app(DocumentStoreRepository::class)->latestVersion($this->documentId);

        if ($version === null) {
            throw new RuntimeException("У документа #{$this->documentId} нет скачанной версии.");
        }

        return $this->analysisCache = app(AnalysisStoreRepository::class)->firstOrCreate($version->id, AnalysisStep::Extraction);
    }
}
