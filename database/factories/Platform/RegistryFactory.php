<?php

declare(strict_types=1);

namespace Database\Factories\Platform;

use App\Models\Platform\Platform;
use App\Models\Platform\Registry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registry>
 */
final class RegistryFactory extends Factory
{
    protected $model = Registry::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'code' => fake()->randomElement(['fz44', 'fz223']),
            'name' => fake()->words(asText: true),
            'settings' => [
                'records_per_page' => fake()->numberBetween(int1: 20, int2: 100),
            ],
        ];
    }
}
