<?php

declare(strict_types=1);

namespace Database\Factories\Ai;

use App\Enums\Ai\AnalysisStep;
use App\Models\Ai\Prompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prompt>
 */
final class PromptFactory extends Factory
{
    protected $model = Prompt::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'step' => AnalysisStep::Screening->value,
            'content' => fake()->paragraph(),
            'version' => 1,
            'priority' => 0,
            'is_active' => true,
        ];
    }
}
