<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Platform;

use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Okpd2Node>
 */
final class Okpd2NodeFactory extends Factory
{
    protected $model = Okpd2Node::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'code' => fake()->unique()->numerify(string: '##.##'),
            'external_id' => (string) fake()->unique()->numberBetween(int1: 1_000_000, int2: 9_999_999),
            'parent_code' => null,
            'level' => fake()->numberBetween(int1: 1, int2: 4),
            'name' => fake()->words(asText: true),
            'synced_at' => null,
        ];
    }
}
