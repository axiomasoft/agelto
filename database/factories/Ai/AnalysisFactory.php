<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Enums\Ai\AnalysisStatus;
use App\Enums\Ai\AnalysisStep;
use App\Models\Ai\Analysis;
use App\Models\Document\DocumentVersion;
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
        ];
    }
}
