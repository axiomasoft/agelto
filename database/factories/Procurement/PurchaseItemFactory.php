<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Procurement;
use App\Models\Procurement\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseItem>
 */
final class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'lot_id' => null,
            'position_number' => fake()->numberBetween(int1: 1, int2: 20),
            'name' => fake()->words(nb: 3, asText: true),
            'characteristics' => null,
            'classifier_scheme' => 'okpd2',
            'classifier_code' => fake()->numerify('##.##.##'),
            'okpd2_ref_id' => null,
            'ktru_ref_id' => null,
            'quantity' => fake()->randomFloat(nbMaxDecimals: 3, min: 1, max: 100),
            'unit' => 'шт',
            'unit_price' => fake()->randomFloat(nbMaxDecimals: 2, min: 100, max: 10000),
            'total_cost' => fake()->randomFloat(nbMaxDecimals: 2, min: 1000, max: 100000),
        ];
    }
}
