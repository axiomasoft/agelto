<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Estimate;
use App\Models\Procurement\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
final class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'section_id' => null,
            'row_index' => $this->faker->unique()->numberBetween(1, 100_000),
            'code' => $this->faker->bothify('ФЕР##-##-###-##'),
            'name' => $this->faker->sentence(),
            'unit' => 'м3',
            'quantity' => $this->faker->randomFloat(6, 1, 1_000),
            'unit_price' => $this->faker->randomFloat(2, 100, 100_000),
            'total_price' => $this->faker->randomFloat(2, 100, 1_000_000),
            'attributes' => null,
        ];
    }
}
