<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\EvalRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvalRun>
 */
final class EvalRunFactory extends Factory
{
    protected $model = EvalRun::class;

    public function definition(): array
    {
        return [
            'document_type' => AiDocumentType::Estimate->value,
            'task' => AnalysisStep::Extraction->value,
            'dataset_version' => fake()->semver(),
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'prompt_version_set' => null,
            'metrics' => ['precision' => 0.9, 'recall' => 0.85, 'f1' => 0.87],
            'ci_status' => 'pass',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
