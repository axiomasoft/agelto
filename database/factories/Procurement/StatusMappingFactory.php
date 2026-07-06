<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Registry;
use App\Models\Procurement\StatusMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusMapping>
 */
final class StatusMappingFactory extends Factory
{
    protected $model = StatusMapping::class;

    public function definition(): array
    {
        return [
            'registry_id' => Registry::factory(),
            'source_status' => fake()->unique()->word(),
            'canonical_status' => fake()->randomElement(['announced', 'bidding', 'evaluation', 'awarded', 'completed', 'cancelled', 'unsuccessful', 'unknown']),
        ];
    }
}
