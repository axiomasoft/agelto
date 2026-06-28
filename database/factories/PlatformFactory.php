<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Platform>
 */
final class PlatformFactory extends Factory
{
    protected $model = Platform::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'ЕИС',
            'code' => 'eis-'.fake()->numerify('######'),
            'url' => 'https://zakupki.gov.ru',
        ];
    }
}
