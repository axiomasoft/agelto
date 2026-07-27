<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Estimate;

use App\Enums\Procurement\ResourceType;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Procurement\Estimate\Resource>
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
            'unit_base' => 'т',
            'unit_multiplier' => '1.000000',
            'quantity' => $this->faker->randomFloat(6, 1, 500),
            'price_base' => $this->faker->randomFloat(2, 10, 50_000),
            'price_curr' => $this->faker->randomFloat(2, 10, 50_000),
            'index_code' => $this->faker->bothify('ИПР-##'),
            'index_value' => $this->faker->randomFloat(6, 1, 10),
        ];
    }
}
