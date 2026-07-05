<?php

declare(strict_types=1);

namespace Database\Factories\Reference;

use App\Models\Reference\Ktru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ktru>
 */
final class KtruFactory extends Factory
{
    protected $model = Ktru::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify(string: '##.##.##.###-#####'),
            'parent_code' => null,
            'level' => fake()->numberBetween(int1: 1, int2: 5),
            'name' => fake()->words(asText: true),
            'okpd2_code' => fake()->numerify(string: '##.##.##'),
            'valid_from' => fake()->dateTimeBetween(startDate: '-5 years')->format('Y-m-d'),
            'valid_to' => null,
            'attributes' => null,
        ];
    }
}
