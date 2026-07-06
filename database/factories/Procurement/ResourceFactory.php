<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\ResourceType;
use App\Models\Procurement\Item;
use App\Models\Procurement\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Procurement\Resource>
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
