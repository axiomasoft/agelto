<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
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
            'step' => AnalysisStep::Screening,
            'status' => AnalysisStatus::Pending,
            'document_type' => null,
            'result' => null,
            'provider' => null,
            'model' => null,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'cached_tokens' => null,
            'total_tokens' => null,
            'cost_micros' => null,
            'locked_by' => null,
            'locked_until' => null,
            'attempts' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
            'failed_permanently' => false,
            'started_at' => null,
            'finished_at' => null,
            'cache_id' => null,
            'cache_expires_at' => null,
            'rules_hash' => null,
            'progress' => null,
            'review_status' => ReviewStatus::Unreviewed,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'operator_comment' => null,
            'parent_analysis_id' => null,
            'superseded_at' => null,
            'is_shadow' => false,
        ];
    }

    /**
     * `analysis_run_id` (NOT NULL, D93) не выражается независимым `AnalysisRun::factory()` в
     * `definition()` — он завёл бы прогон над ЧУЖОЙ версией документа (нестыковка с
     * `document_version_id` выше). Хук резолвит `document_version_id` УЖЕ созданным вложенным
     * фабрикам и заводит прогон над ТОЙ ЖЕ версией; явный `->state(['analysis_run_id' => …])`
     * вызывающего теста хук не перебивает. Переиспользует уже АКТИВНЫЙ прогон той же версии, если
     * такой есть (partial unique `prc_ai_analysis_runs_active_version_unique`) — тесты, заводящие
     * НЕСКОЛЬКО `Analysis` на одну версию (extraction+screening, shadow, rerun), не должны знать
     * про эту связь и не обязаны сами передавать `analysis_run_id`.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Analysis $analysis): void {
            if ($analysis->analysis_run_id !== null) {
                return;
            }

            $active = AnalysisRun::query()
                ->where('document_version_id', $analysis->document_version_id)
                ->whereNull('finished_at')
                ->first();

            $analysis->analysis_run_id = $active?->id ?? AnalysisRun::factory()->create([
                'document_version_id' => $analysis->document_version_id,
            ])->id;
        });
    }
}
