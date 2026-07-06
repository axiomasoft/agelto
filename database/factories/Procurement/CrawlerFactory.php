<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\CrawlerMode;
use App\Enums\Procurement\SourceMethod;
use App\Models\Procurement\Crawler;
use App\Models\Procurement\Registry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crawler>
 */
final class CrawlerFactory extends Factory
{
    protected $model = Crawler::class;

    public function definition(): array
    {
        return [
            'registry_id' => Registry::factory(),
            'name' => fake()->words(asText: true),
            'mode' => CrawlerMode::Fresh,
            'source_method' => SourceMethod::Csv,
            'fallback_method' => SourceMethod::Html,
            'filter' => null,
            'schedule' => '*/15 * * * *',
            'options' => [
                'records_per_page' => fake()->numberBetween(int1: 20, int2: 100),
            ],
            'is_active' => true,
        ];
    }
}
