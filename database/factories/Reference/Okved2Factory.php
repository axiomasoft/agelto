<?php

declare(strict_types=1);

namespace Database\Factories\Reference;

use App\Models\Reference\Okved2;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Okved2>
 */
final class Okved2Factory extends Factory
{
    protected $model = Okved2::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify(string: '##.##'),
            'parent_code' => null,
            'level' => fake()->numberBetween(int1: 1, int2: 4),
            'name' => fake()->words(asText: true),
            'valid_from' => fake()->dateTimeBetween(startDate: '-5 years')->format('Y-m-d'),
            'valid_to' => null,
            'attributes' => null,
        ];
    }
}
