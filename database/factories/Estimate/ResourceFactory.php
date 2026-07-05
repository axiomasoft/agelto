<?php

declare(strict_types=1);

namespace Database\Factories\Estimate;

use App\Enums\Estimate\ResourceType;
use App\Models\Estimate\Item;
use App\Models\Estimate\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Estimate\Resource>
 */
final class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'resource_type' => ResourceType::Material,
            'code' => $this->faker->bothify('РЕС-####'),
            'name' => $this->faker->words(3, true),
            'unit' => 'т',
            'quantity' => $this->faker->randomFloat(6, 1, 500),
            'price' => $this->faker->randomFloat(2, 10, 50_000),
        ];
    }
}
