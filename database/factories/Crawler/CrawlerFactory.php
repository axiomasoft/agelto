<?php

declare(strict_types=1);

namespace Database\Factories\Crawler;

use App\Models\Crawler\Crawler;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Crawler>
 */
final class CrawlerFactory extends Factory
{
    protected $model = Crawler::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/results.html',
        ];
    }
}
