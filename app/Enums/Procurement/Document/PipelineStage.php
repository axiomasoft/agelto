<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

use App\Enums\Procurement\Ai\AnalysisStep;

/**
 * Стадия конвейера документа (v2-аналог v1 `App\Enums\Pipeline\PipelineStage` +
 * `AnalysisStatus::stage()`, `Old/app/Console/Commands/PipelineRetryFailedLinksCommand.php:52`).
 * v1 держал стадию в самих значениях статуса анализа (`PrimaryAnalysisFailed`,
 * `EstimateAnalysisFailed`); v2-схема проще — `prc_documents.status` не растёт дальше
 * `downloaded` при провале подготовки (стадия prepare), а `prc_ai_analyses.step`
 * (`AnalysisStep`) уже прямо называет screening/extraction — стадия анализа читается
 * из `step`, а не реконструируется из статуса.
 */
enum PipelineStage: string
{
    case Prepare = 'prepare';
    case Screening = 'screening';
    case Extraction = 'extraction';

    /**
     * Документы ретраятся только на стадии подготовки — `screened`/`extracted` не хранятся
     * на `Document`, а живут в `Ai\Analysis::step` (см. `fromAnalysisStep()`).
     */
    public static function forDocumentRetry(): self
    {
        return self::Prepare;
    }

    public static function fromAnalysisStep(AnalysisStep $step): self
    {
        return match ($step) {
            AnalysisStep::Screening => self::Screening,
            AnalysisStep::Extraction => self::Extraction,
        };
    }
}
