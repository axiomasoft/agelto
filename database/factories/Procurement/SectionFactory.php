<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\Estimate;
use App\Models\Procurement\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
final class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'parent_id' => null,
            'position' => $this->faker->numberBetween(1, 20),
            'title' => $this->faker->sentence(3),
            'section_number' => $this->faker->bothify('Раздел ##'),
        ];
    }
}
