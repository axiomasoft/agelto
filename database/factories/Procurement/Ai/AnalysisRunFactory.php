<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\AnalysisRunStatus;
use App\Enums\Procurement\RunMode;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisRun>
 */
final class AnalysisRunFactory extends Factory
{
    protected $model = AnalysisRun::class;

    public function definition(): array
    {
        return [
            'document_version_id' => DocumentVersion::factory(),
            'mode' => RunMode::Auto,
            'status' => AnalysisRunStatus::Running,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'cached_tokens' => null,
            'total_tokens' => null,
            'cost_micros' => null,
            'initiated_by' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }

    /**
     * Завершённый прогон (`finished_at` заполнен) — нужен тестам partial unique
     * (§3.2 root/document-contour.md): второй прогон над той же версией легален, только пока
     * первый не финиширован.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AnalysisRunStatus::Succeeded,
            'finished_at' => now(),
        ]);
    }
}
