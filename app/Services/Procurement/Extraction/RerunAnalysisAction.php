<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Support\Facades\DB;
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
        $documentVersion = $analysis->documentVersion;

        if ($documentVersion === null) {
            throw new RuntimeException("Перезапуск анализа #{$analysis->id} невозможен: версия документа не найдена.");
        }

        return DB::transaction(function () use ($analysis, $documentVersion): Analysis {
            $document = Document::query()->lockForUpdate()->findOrFail($documentVersion->document_id);
            $current = Analysis::query()->lockForUpdate()->findOrFail($analysis->id);

            if ($current->superseded_at !== null || $current->is_shadow
                || ! in_array($current->status, [AnalysisStatus::Succeeded, AnalysisStatus::Failed], true)
                || $document->removed_at !== null
                || $this->documents->latestVersion($document->id)?->id !== $current->document_version_id
                || $this->runs->findActive($current->document_version_id) !== null
                || Document::query()->whereKey($document->id)->whereRaw('locked_until >= CURRENT_TIMESTAMP')->exists()
                || Analysis::query()->where('document_version_id', $current->document_version_id)
                    ->whereRaw('locked_until >= CURRENT_TIMESTAMP')->exists()) {
                throw new RuntimeException('Перезапуск невозможен: анализ устарел или документ уже обрабатывается.');
            }

            $analyzer = $current->step === AnalysisStep::Extraction ? $this->extractionAnalyzer($current) : null;
            $this->analyses->update($current, ['superseded_at' => now()]);

            if ($current->step === AnalysisStep::Screening) {
                $extraction = $this->analyses->findActive($current->document_version_id, AnalysisStep::Extraction);

                if ($extraction !== null) {
                    $this->analyses->update($extraction, ['superseded_at' => now()]);
                }
            }

            $this->runs->start($current->document_version_id, RunMode::Manual);
            $newAnalysis = $this->analyses->createRerun($current);
            $current->corrections()->whereNull('applied_at')->update(['applied_at' => now()]);
            $this->documents->update($document, ['status' => $this->preStageStatus($current->step)]);

            if ($analyzer !== null) {
                $analyzer->dispatchExtraction($document->id);
            } else {
                ScreeningJob::dispatch($document->id)->afterCommit();
            }

            return $newAnalysis;
        });
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

        $type = is_string($documentType) ? AiDocumentType::tryFrom($documentType) : null;
        $analyzer = $type !== null ? $this->analyzers->for($type) : null;

        if ($analyzer === null || ! $analyzer->hasExtraction()) {
            $documentTypeLabel = is_scalar($documentType) ? (string) $documentType : '—';

            throw new RuntimeException(
                "Перезапуск извлечения невозможен: у анализа #{$analysis->id} тип документа «{$documentTypeLabel}» без анализатора со стадией извлечения."
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
