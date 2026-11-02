<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Dto\Procurement\Ai\Screening\ScreeningDecision;
use App\Dto\Procurement\Ai\Screening\StoredScreeningResult;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
final class AnalysisFactory extends Factory
{
    protected $model = Analysis::class;

    public function definition(): array
    {
        return [
            'document_version_id' => DocumentVersion::factory(),
            'analysis_run_id' => null,
            'step' => AnalysisStep::Screening,
            'status' => AnalysisStatus::Succeeded,
            'document_type' => null,
            'result' => null,
            'provider' => null,
            'model' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'cached_tokens' => null,
            'total_tokens' => null,
            'cost_micros' => null,
            'started_at' => null,
            'finished_at' => null,
            'review_status' => ReviewStatus::Unreviewed,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'operator_comment' => null,
            'parent_analysis_id' => null,
            'superseded_at' => null,
            'is_shadow' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Analysis $analysis): void {
            if (
                $analysis->step === AnalysisStep::Screening
                && $analysis->status === AnalysisStatus::Succeeded
                && $analysis->result === null
                && $analysis->superseded_at === null
                && ! $analysis->is_shadow
            ) {
                $analysis->document_type ??= AiDocumentType::Unknown->value;
                $analysis->result = (new StoredScreeningResult(
                    confidence: 1.0,
                    summary: 'Тестовый результат screening',
                    estimateCardinality: EstimateCardinality::Unknown,
                    tags: [],
                    classificationNote: null,
                    pageCountEstimate: null,
                    decision: new ScreeningDecision(
                        disposition: ScreeningDisposition::Ignore,
                        reason: ScreeningDecisionReason::UnknownType,
                        extractionDispatched: false,
                    ),
                ))->toArray();
            }

        });
    }
}
