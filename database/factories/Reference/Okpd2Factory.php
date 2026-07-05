<?php

declare(strict_types=1);

namespace Database\Factories\Reference;

use App\Models\Reference\Okpd2;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Okpd2>
 */
final class Okpd2Factory extends Factory
{
    protected $model = Okpd2::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify(string: '##.##.##'),
            'parent_code' => null,
            'level' => fake()->numberBetween(int1: 1, int2: 5),
            'name' => fake()->words(asText: true),
            'valid_from' => fake()->dateTimeBetween(startDate: '-5 years')->format('Y-m-d'),
            'valid_to' => null,
            'attributes' => null,
        ];
    }
}
