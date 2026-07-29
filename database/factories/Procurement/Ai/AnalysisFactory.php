<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\ReviewStatus;
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
}
