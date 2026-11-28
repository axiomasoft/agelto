<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Lot;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lot>
 */
final class LotFactory extends Factory
{
    protected $model = Lot::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'lot_number' => fake()->unique()->numberBetween(int1: 1, int2: 1000),
            'lot_guid' => null,
            'subject' => fake()->sentence(),
            'status' => ProcurementStatus::Bidding,
            'is_centralized' => false,
            'initial_price' => fake()->randomFloat(nbMaxDecimals: 2, min: 10000, max: 1000000),
            'currency' => 'RUB',
            'okpd2_code' => null,
            'okved_code' => null,
            'attributes' => null,
        ];
    }
}
