<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Estimate;

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
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
            'unit_base' => 'м3',
            'unit_multiplier' => '1.000000',
            'quantity' => $this->faker->randomFloat(6, 1, 1_000),
            'unit_price_base' => $this->faker->randomFloat(2, 100, 100_000),
            'unit_price_curr' => $this->faker->randomFloat(2, 100, 100_000),
            'total_price_base' => $this->faker->randomFloat(2, 100, 1_000_000),
            'total_price_curr' => $this->faker->randomFloat(2, 100, 1_000_000),
            'index_code' => $this->faker->bothify('ИПР-##'),
            'index_value' => $this->faker->randomFloat(6, 1, 10),
            'source_position_number' => (string) $this->faker->numberBetween(1, 200),
            'work_type_id' => null,
            'attributes' => null,
        ];
    }
}
