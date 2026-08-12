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
            'code' => 'fz44',
            'name' => fake()->words(asText: true),
            'settings' => [
                'records_per_page' => fake()->numberBetween(int1: 20, int2: 100),
            ],
        ];
    }

    public function eis(): static
    {
        return $this->state(function (): array {
            $platform = Platform::query()->firstOrCreate(
                attributes: ['code' => 'eis'],
                values: [
                    'name' => 'Единая информационная система',
                    'base_url' => 'https://zakupki.gov.ru',
                    'is_active' => true,
                    'is_public' => true,
                ],
            );

            return ['platform_id' => $platform->getKey()];
        });
    }
}
