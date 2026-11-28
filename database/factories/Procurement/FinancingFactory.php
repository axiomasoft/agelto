<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Financing;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Financing>
 */
final class FinancingFactory extends Factory
{
    protected $model = Financing::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'lot_id' => null,
            'kind' => 'budget',
            'kbk' => fake()->numerify('###################'),
            'kbk_ref_id' => null,
            'kvr' => fake()->numerify('###'),
            'receipt_code' => null,
            'fiscal_year' => (int) date('Y'),
            'is_beyond_horizon' => false,
            'amount' => fake()->randomFloat(nbMaxDecimals: 2, min: 10000, max: 1000000),
        ];
    }
}
