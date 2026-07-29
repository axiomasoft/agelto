<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\RunMode;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use RuntimeException;

/**
 * Операторский re-run стадии анализа (P9.2, D24, Intent «запустить заново разбор»).
 *
 * Порядок ОБЯЗАТЕЛЕН (partial unique `prc_ai_analyses_active_version_step_unique`, обычный —
 * не `DEFERRABLE` — индекс, проверяется на `INSERT` немедленно): (1) `$analysis` помечается
 * `superseded_at` — освобождает пару `(document_version_id, step)`; (2) создаётся новый
 * `pending`-анализ с `parent_analysis_id`; (3) непогашенные коррекции старого анализа получают
 * `applied_at` (они были основанием re-run, `CorrectionAwareComposer` подмешает их в USER-часть
 * следующего вызова через `parent_analysis_id` новой записи); (4) документ откатывается к
 * статусу ПЕРЕД этой стадией — статус-гейты job'ов стадии требуют именно pre-stage статус
 * (`prepared`/`screened`), иначе передиспатч штатного job'а стадии молча становится no-op;
 * (5) штатный job стадии диспатчится заново — для извлечения его называет АНАЛИЗАТОР типа
 * документа (реестр), ядро имени job'а не знает.
 *
 * Результат заменённого прогона удаляет персистер СВОЕГО типа ПОСЛЕ успешного персиста нового
 * прогона (`supersededAnalysisId`, Implementation Rules P9.2) — не эта Action: при неудаче
 * нового прогона старый результат остаётся видимым оператору.
 */
final readonly class RerunAnalysisAction
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private DocumentStoreRepository $documents,
        private DocumentAnalyzerRegistry $analyzers,
    ) {}

    public function run(Analysis $analysis): Analysis
    {
        $document = Document::query()->findOrFail($analysis->documentVersion->document_id);

        // Анализатор резолвится ДО любой мутации: перезапуск, для которого некому диспатчить
        // стадию, не должен оставлять за собой superseded-анализ и откаченный статус документа.
        $analyzer = $analysis->step === AnalysisStep::Extraction ? $this->extractionAnalyzer($analysis) : null;

        $this->analyses->update($analysis, ['superseded_at' => now()]);

        // Launch-точка (§3.5 root/document-contour.md): перезапуск — НОВЫЙ прогон, а не
        // продолжение старого; заводится ДО `createRerun()`, которая резолвит его как активный
        // для нового анализа (Implementation Rule 9 P1.6).
        $this->runs->start($analysis->document_version_id, RunMode::Manual);

        $newAnalysis = $this->analyses->createRerun($analysis);

        $analysis->corrections()->whereNull('applied_at')->update(['applied_at' => now()]);

        $this->documents->update($document, ['status' => $this->preStageStatus($analysis->step)]);

        // `$analyzer !== null` ⟺ стадия извлечения (резолв выше); неизвестная стадия сюда не
        // доходит — на ней падает исчерпывающий `match` в `preStageStatus()` строкой выше.
        if ($analyzer !== null) {
            $analyzer->dispatchExtraction($document->id);
        } else {
            ScreeningJob::dispatch($document->id)->afterCommit();
        }

        return $newAnalysis;
    }

    /**
     * Тип документа для перезапуска стадии извлечения. Собственный `document_type` у анализа
     * извлечения в боевом контуре пуст (`AnalysisStoreRepository::firstOrCreate()` его не пишет),
     * поэтому ось берётся оттуда, где её ЗАПОЛНИЛИ, — с активного анализа скрининга той же версии
     * документа. Стадия извлечения без предшествующего скрининга в боевом контуре недостижима:
     * статус `screened` документу ставит ТОЛЬКО `ScreeningOutcomeHandler`, а статус-гейт job'а
     * извлечения требует именно его.
     */
    private function extractionAnalyzer(Analysis $analysis): DocumentAnalyzer
    {
        $documentType = $analysis->document_type ?? Analysis::query()
            ->active()
            ->where('document_version_id', $analysis->document_version_id)
            ->where('step', AnalysisStep::Screening)
            ->value('document_type');

        $type = $documentType !== null ? AiDocumentType::tryFrom((string) $documentType) : null;
        $analyzer = $type !== null ? $this->analyzers->for($type) : null;

        if ($analyzer === null || ! $analyzer->hasExtraction()) {
            throw new RuntimeException(
                "Перезапуск извлечения невозможен: у анализа #{$analysis->id} тип документа «".($documentType ?? '—').'» без анализатора со стадией извлечения.'
            );
        }

        return $analyzer;
    }

    private function preStageStatus(AnalysisStep $step): DocumentStatus
    {
        return match ($step) {
            AnalysisStep::Screening => DocumentStatus::Prepared,
            AnalysisStep::Extraction => DocumentStatus::Screened,
        };
    }
}
