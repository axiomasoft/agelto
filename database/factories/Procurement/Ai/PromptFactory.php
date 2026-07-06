<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\Prompt;
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
