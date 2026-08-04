<?php

declare(strict_types=1);

namespace App\Services\Procurement\Ai;

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Repositories\Procurement\Ai\AnalysisReadRepository;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;

/**
 * ЕДИНЫЙ сейм записи слепка расхода на анализе и на прогоне (P4.1, D5/D25/D39,
 * §3.6 root/document-contour.md) — заменяет прямые терминальные `AnalysisStoreRepository::update()`
 * во ВСЕХ восьми точках завершения анализа (screening/extraction succeeded/skipped, оба
 * `failed()` в job'ах, оба исхода `ShadowAnalysisJob`) и три прямых
 * `AnalysisRunStoreRepository::markSucceeded()` в `ScreeningOutcomeHandler` (ветки без
 * извлечения) — единственная точка вычисления, не восемь копий агрегата.
 *
 * Слепок прогона персистится КАК ПОБОЧНЫЙ ЭФФЕКТ терминации: `finalizeAnalysis()` всегда
 * перечитывает `$analysis->analysisRun` ПОСЛЕ записи — если он уже терминирован (этим же вызовом
 * через `AnalysisStoreRepository::maybeTerminateRun()` либо раньше), слепок прогона пересчитывается
 * заново (Implementation Rule 5 — идемпотентный пересчёт, не инкремент). Это же покрывает
 * теневой анализ, доигравший ПОСЛЕ терминации основного прогона: `analysis_run_id` у него общий
 * с основным (`createShadow()`), и следующий пересчёт подтянет его вклад без отдельного носителя.
 */
final readonly class UsageSnapshotRecorder
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private AnalysisReadRepository $analysisReads,
        private LlmCallReadRepository $calls,
        private DocumentAnalyzerRegistry $registry,
    ) {}

    /**
     * Терминальная запись анализа: `$attributes` — то, что сегодня пишет вызывающий (`status`,
     * `result`, `finished_at`, при наличии `provider`/`model`), слепок расхода домешивается сюда
     * же — ОДНА транзакция персистентности `AnalysisStoreRepository::update()`, не вторая правка
     * следом. Вызывающий обязан звать этот метод ТОЛЬКО из терминальных переходов (succeeded/
     * failed/skipped) — нетерминальные записи (`running`, `progress`-чекпоинт) остаются на
     * `AnalysisStoreRepository::update()` напрямую.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finalizeAnalysis(Analysis $analysis, array $attributes): void
    {
        $this->analyses->update($analysis, [...$attributes, ...$this->snapshotForAnalysis($analysis)]);

        $this->syncRunSnapshot($analysis->analysisRun);
    }

    /**
     * Терминация прогона БЕЗ сопутствующего перехода статуса анализа (`ScreeningOutcomeHandler`:
     * скрининг уже `succeeded`, а извлечения не будет/оно не диспатчится) — обёртка над
     * `AnalysisRunStoreRepository::markSucceeded()`, добавляющая запись слепка тем же вызовом.
     */
    public function markRunSucceeded(AnalysisRun $run): void
    {
        $this->runs->markSucceeded($run);

        $this->syncRunSnapshot($run);
    }

    private function syncRunSnapshot(?AnalysisRun $run): void
    {
        if ($run === null || $run->finished_at === null) {
            return;
        }

        $this->runs->recordUsage($run, $this->analysisReads->usageSummaryForRun($run->id));
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int, cost_micros: int}
     */
    private function snapshotForAnalysis(Analysis $analysis): array
    {
        return $this->calls->usageSummary($analysis->id, $this->purposesFor($analysis));
    }

    /**
     * Принадлежность назначения анализу (D39) — состав слепка. Теневой анализ (`is_shadow`)
     * пишет ВСЕГДА `purpose=shadow` независимо от того, какой шаг дублирует (`ShadowAnalysisJob`),
     * поэтому теневая ветка проверяется ПЕРВОЙ. `screening` — ядерная константа шага, а не
     * назначение типа документа, поэтому не идёт через реестр (тот же приём, что `classify()`
     * `ScreeningProcessor`, где `CoreLlmPurpose::Screening` — литерал, не выбор типа). Только
     * `extraction` спрашивает реестр: назначения шага извлечения принадлежат анализатору типа
     * (P2.5), литеральный список здесь запрещён (Validation 4) — второй тип документа получит свой
     * состав слепка без правки этого файла (приёмка D14). Тип по умолчанию — `Estimate`, тот же
     * фолбэк, что уже применяет `ExtractionOrchestrator::run()` (единственный тип сегодня, столбец
     * `document_type` анализа extraction ещё не заполняется).
     *
     * @return list<LlmPurpose>
     */
    private function purposesFor(Analysis $analysis): array
    {
        if ($analysis->is_shadow) {
            return [CoreLlmPurpose::Shadow];
        }

        if ($analysis->step === AnalysisStep::Screening) {
            return [CoreLlmPurpose::Screening];
        }

        $documentType = AiDocumentType::from($analysis->document_type ?? AiDocumentType::Estimate->value);
        $analyzer = $this->registry->for($documentType);

        return $analyzer?->purposes() ?? [];
    }
}
