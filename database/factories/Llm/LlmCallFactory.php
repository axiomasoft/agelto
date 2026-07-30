<?php

declare(strict_types=1);

namespace Database\Factories\Llm;

use App\Enums\Llm\CoreLlmPurpose;
use App\Models\Llm\LlmCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmCall>
 */
final class LlmCallFactory extends Factory
{
    protected $model = LlmCall::class;

    public function definition(): array
    {
        return [
            'analysis_id' => null,
            'purpose' => CoreLlmPurpose::Screening->value,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'prompt_version_set' => null,
            'cache_id' => null,
            'request_ref' => null,
            'response_ref' => null,
            'prompt_tokens' => fake()->numberBetween(int1: 100, int2: 5000),
            'completion_tokens' => fake()->numberBetween(int1: 50, int2: 2000),
            'cached_tokens' => 0,
            'total_tokens' => fake()->numberBetween(int1: 150, int2: 7000),
            'cost_micros' => fake()->numberBetween(int1: 100, int2: 100000),
            'success' => true,
            'error' => null,
            'duration_ms' => fake()->numberBetween(int1: 100, int2: 30000),
            'called_at' => now(),
        ];
    }
}
