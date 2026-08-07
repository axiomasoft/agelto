<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Platform;

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registry>
 */
final class RegistryFactory extends Factory
{
    protected $model = Registry::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            // `config('crawler.filters')` знает ровно один код — `fz44`; случайный выбор делал бы
            // гейт скоупа `Crawler::saving()` флаки (D108, research/11 §2). Тестам, которым нужен
            // `fz223`, задают код ЯВНО.
            'code' => 'fz44',
            'name' => fake()->words(asText: true),
            'settings' => [
                'records_per_page' => fake()->numberBetween(int1: 20, int2: 100),
            ],
        ];
    }
}
