<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Platform;

use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Platform>
 */
final class PlatformFactory extends Factory
{
    protected $model = Platform::class;

    public function definition(): array
    {
        return [
            'code' => str_replace('-', '_', fake()->unique()->slug(nbWords: 2)),
            'name' => fake()->company(),
            'base_url' => fake()->url(),
            'is_active' => true,
            'is_public' => true,
        ];
    }

    /**
     * Служебная площадка «Обучалка» (§1.3 root/document-contour.md, D91) — тот же профиль,
     * что заводит сидер.
     */
    public function training(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => Platform::TRAINING_CODE,
            'name' => 'Обучалка',
            'base_url' => null,
            'is_active' => false,
            'is_public' => false,
        ]);
    }
}
