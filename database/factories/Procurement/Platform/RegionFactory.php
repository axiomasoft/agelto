<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Platform;

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
final class RegionFactory extends Factory
{
    protected $model = Region::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'external_id' => fake()->unique()->numerify(string: '##'),
            'name' => fake()->city(),
            'region_code' => fake()->numerify(string: '##'),
        ];
    }
}
