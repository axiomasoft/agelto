<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Jobs\Procurement\EstimateExtractionJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;

/**
 * Операторский re-run стадии анализа (P9.2, D24, Intent «запустить заново разбор»).
 *
 * Порядок ОБЯЗАТЕЛЕН (partial unique `prc_ai_analyses_active_version_step_unique`, обычный —
 * не `DEFERRABLE` — индекс, проверяется на `INSERT` немедленно): (1) `$analysis` помечается
 * `superseded_at` — освобождает пару `(document_version_id, step)`; (2) создаётся новый
 * `pending`-анализ с `parent_analysis_id`; (3) непогашенные коррекции старого анализа получают
 * `applied_at` (они были основанием re-run, `CorrectionAwareComposer` подмешает их в USER-часть
 * следующего вызова через `parent_analysis_id` новой записи); (4) документ откатывается к
 * статусу ПЕРЕД этой стадией — `ScreeningJob`/`EstimateExtractionJob::statusGuardPassed()`
 * требуют именно pre-stage статус (`prepared`/`screened`), иначе передиспатч штатного job'а
 * стадии молча становится no-op; (5) штатный job стадии диспатчится заново.
 *
 * Сметы `$analysis` удаляет `EstimatePersister` ПОСЛЕ успешного персиста нового прогона
 * (`supersededAnalysisId`, Implementation Rules P9.2) — не эта Action: при неудаче нового
 * прогона старые сметы остаются видимыми оператору.
 */
final readonly class RerunAnalysisAction
{
    public function __construct(
        private AnalysisStoreRepository $analyses,
        private DocumentStoreRepository $documents,
    ) {}

    public function run(Analysis $analysis): Analysis
    {
        $document = Document::query()->findOrFail($analysis->documentVersion->document_id);

        $this->analyses->update($analysis, ['superseded_at' => now()]);

        $newAnalysis = $this->analyses->createRerun($analysis);

        $analysis->corrections()->whereNull('applied_at')->update(['applied_at' => now()]);

        $this->documents->update($document, ['status' => $this->preStageStatus($analysis->step)]);

        match ($analysis->step) {
            AnalysisStep::Screening => ScreeningJob::dispatch($document->id)->afterCommit(),
            AnalysisStep::Extraction => EstimateExtractionJob::dispatch($document->id)->afterCommit(),
        };

        return $newAnalysis;
    }

    private function preStageStatus(AnalysisStep $step): DocumentStatus
    {
        return match ($step) {
            AnalysisStep::Screening => DocumentStatus::Prepared,
            AnalysisStep::Extraction => DocumentStatus::Screened,
        };
    }
}
