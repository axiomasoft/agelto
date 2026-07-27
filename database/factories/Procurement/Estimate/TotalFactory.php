<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Estimate;

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Total;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Total>
 */
final class TotalFactory extends Factory
{
    protected $model = Total::class;

    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'parent_id' => null,
            'position' => $this->faker->numberBetween(1, 20),
            'label' => 'Итого',
            'kind' => null,
            'amount' => $this->faker->randomFloat(2, 1_000, 1_000_000),
            'chapter_number' => null,
            'rate' => null,
        ];
    }
}
