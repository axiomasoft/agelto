<?php

declare(strict_types=1);

namespace Database\Factories\Llm;

use App\Models\Llm\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provider>
 */
final class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(3),
            'driver' => 'gemini',
            'base_url' => null,
            'options' => null,
            'is_active' => true,
        ];
    }
}
