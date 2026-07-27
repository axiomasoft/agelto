<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Estimate;

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\WorkType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkType>
 */
final class WorkTypeFactory extends Factory
{
    protected $model = WorkType::class;

    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'parent_id' => null,
            'position' => $this->faker->numberBetween(1, 20),
            'title' => $this->faker->words(3, true),
            'overhead_code' => $this->faker->bothify('НР-##'),
            'overhead_rate' => $this->faker->randomFloat(6, 50, 120),
            'overhead_rate_curr' => $this->faker->randomFloat(6, 50, 120),
            'profit_code' => $this->faker->bothify('СП-##'),
            'profit_rate' => $this->faker->randomFloat(6, 30, 90),
            'profit_rate_curr' => $this->faker->randomFloat(6, 30, 90),
        ];
    }
}
