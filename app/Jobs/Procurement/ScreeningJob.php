<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Screening\ScreeningOutcomeHandler;
use App\Services\Procurement\Screening\ScreeningProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use RuntimeException;
use Throwable;

/**
 * Стадия скрининга (ТЗ 03 §3.4, P5.1/P5.2): `prepared → running → succeeded/skipped` на
 * `Ai\Analysis(step=screening)` (lease/backoff — на самом `Analysis`, не на `Document`, т.к.
 * несколько версий документа могут проходить независимый скрининг). Single-shot
 * (`ShouldBeUnique`, D39 — короткий вызов, не checkpoint-requeue) — защита от двойного диспатча
 * `PrepareDocumentJob`/retry-командой (P4.3). Ветвление по результату (`ScreeningOutcomeHandler`,
 * P5.2) выполняется тут же, в том же прогоне `process()`, после успешного `ScreeningProcessor`.
 */
final class ScreeningJob extends PipelineJob implements ShouldBeUnique
{
    private ?Document $documentCache = null;

    private ?Analysis $analysisCache = null;

    public function __construct(
        public readonly int $documentId,
    ) {
        $this->onQueue('ai_screening');
    }

    public function uniqueId(): string
    {
        return $this->pipelineUniqueId();
    }

    public function pipelineUniqueId(): string
    {
        return "{$this->documentId}:screening";
    }

    /**
     * `Document::status` не меняется скринингом (branching — P5.2), поэтому guard, помимо
     * `prepared`, обязан заглянуть в саму `Ai\Analysis`: `succeeded`/`skipped` — стадия уже
     * доведена до терминала (успех или `unprocessable`), повторный диспатч (backlog-поллер,
     * ручной ретрай) — no-op, не жжёт токены заново; `failed` — попытки исчерпаны
     * (`failed_permanently`), тоже no-op. Продолжаем только если анализа ещё нет либо он
     * `pending`/`running` (прерванная попытка).
     */
    protected function statusGuardPassed(): bool
    {
        if ($this->document()->status !== DocumentStatus::Prepared) {
            return false;
        }

        $version = app(DocumentStoreRepository::class)->latestVersion($this->documentId);

        if ($version === null) {
            return false;
        }

        // `active()` (P9.1/P9.2, review-фикс): БЕЗ этого скоупа `->first()` без `ORDER BY` мог
        // вернуть superseded-запись после `RerunAnalysisAction` (P9.2) — терминальный статус
        // старого прогона молча блокировал бы передиспатч job'а для нового `pending`-анализа.
        $existing = Analysis::query()
            ->active()
            ->where('document_version_id', $version->id)
            ->where('step', AnalysisStep::Screening)
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

    /**
     * `HasErrorBlock::recordFailure()` фиксирует attempts/backoff на `Analysis`; при исчерпании
     * попыток (`failed_permanently`) дополнительно переводит `status` в `Failed` — единственное
     * место, доводящее декларируемый в докблоке `Analysis` жизненный цикл
     * (`pending→running→succeeded/failed/skipped`) до терминала при провале (docblock `Analysis`).
     */
    protected function recordFailure(Throwable $exception): void
    {
        $analysis = $this->analysis();
        $analysis->recordFailure($exception->getMessage());

        if ($analysis->failed_permanently) {
            // `finished_at` (P4.1, Implementation Rule 2): раньше не проставлялся — отказавший
            // анализ потратил деньги ДО отказа, слепок обязан закрыться тем же терминалом, что
            // succeeded/skipped, а не оставаться "открытым" навсегда.
            app(UsageSnapshotRecorder::class)->finalizeAnalysis($analysis, [
                'status' => AnalysisStatus::Failed,
                'finished_at' => now(),
            ]);
        }
    }

    protected function process(): void
    {
        $document = $this->document();
        $analysis = $this->analysis();

        app(ScreeningProcessor::class)->run($document, $analysis);

        $document->refresh();
        $analysis->refresh();

        app(ScreeningOutcomeHandler::class)->handle($document, $analysis);
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

        return $this->analysisCache = app(AnalysisStoreRepository::class)->firstOrCreate($version->id, AnalysisStep::Screening);
    }
}
