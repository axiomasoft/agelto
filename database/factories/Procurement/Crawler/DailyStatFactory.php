<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\DailyStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyStat>
 */
final class DailyStatFactory extends Factory
{
    protected $model = DailyStat::class;

    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'crawler_id' => Crawler::factory(),
            'listing_found' => fake()->numberBetween(int1: 0, int2: 500),
            'listing_errors' => 0,
            'detail_fetched' => fake()->numberBetween(int1: 0, int2: 500),
            'detail_errors' => 0,
            'docs_indexed' => fake()->numberBetween(int1: 0, int2: 500),
            'docs_errors' => 0,
            'screened' => fake()->numberBetween(int1: 0, int2: 500),
            'estimates_found' => fake()->numberBetween(int1: 0, int2: 200),
            'screen_errors' => 0,
            'extracted_items' => fake()->numberBetween(int1: 0, int2: 200),
            'extract_errors' => 0,
        ];
    }
}
