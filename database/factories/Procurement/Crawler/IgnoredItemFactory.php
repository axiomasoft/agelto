<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Platform\Registry;
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
            'registry_number' => fake()->unique()->numerify('##########-#######-##'),
            'reason_category' => 'duplicate',
            'reason' => null,
        ];
    }
}
