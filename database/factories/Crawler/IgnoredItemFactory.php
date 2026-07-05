<?php

declare(strict_types=1);

namespace Database\Factories\Crawler;

use App\Models\Crawler\IgnoredItem;
use App\Models\Platform\Registry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IgnoredItem>
 */
final class IgnoredItemFactory extends Factory
{
    protected $model = IgnoredItem::class;

    public function definition(): array
    {
        return [
            'registry_id' => Registry::factory(),
            'source_number' => fake()->unique()->numerify('##########-#######-##'),
            'reason_category' => 'duplicate',
            'reason' => null,
        ];
    }
}
