<?php

declare(strict_types=1);

namespace Database\Factories\Llm;

use App\Enums\Llm\CorePromptStep;
use App\Enums\Llm\DocumentTypeScope;
use App\Models\Llm\Prompt;
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
            'document_type' => DocumentTypeScope::Any->value,
            'key' => fake()->unique()->slug(2),
            'step' => CorePromptStep::ScreeningGlobal->value,
            'content' => fake()->paragraph(),
            'version' => 1,
            'priority' => 0,
            'is_active' => true,
        ];
    }
}
