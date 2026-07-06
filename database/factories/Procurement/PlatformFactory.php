<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Platform>
 */
final class PlatformFactory extends Factory
{
    protected $model = Platform::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(nbWords: 2),
            'name' => fake()->company(),
            'base_url' => fake()->url(),
            'is_active' => true,
        ];
    }
}
