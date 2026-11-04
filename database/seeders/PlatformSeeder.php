<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Создаёт служебную площадку «Обучалка» для `estimate:gold:import`/`estimate:dataset:import`.
 * Площадка — данные каталога, поэтому создаётся сидером, а не миграцией схемы.
 * Нужна и в production: импорт сохраняет её `platform_id`. Входит в базовый набор сидов,
 * который `DatabaseSeeder` запускает до local/testing-гейта демонстрационных данных.
 *
 * Повторный запуск обновляет существующую строку по уникальному `code`.
 */
final class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $attributes = [
            'name' => 'Обучалка',
            'base_url' => null,
            'is_active' => false,
            'is_public' => false,
        ];

        $existing = Platform::query()->where('code', Platform::TRAINING_CODE)->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        // `uuid` — явно: `DatabaseSeeder` несёт `WithoutModelEvents`, `Platform::booted()`
        // (`creating`-хук) здесь не срабатывает.
        Platform::query()->create([
            'code' => Platform::TRAINING_CODE,
            'uuid' => (string) Str::uuid7(),
            ...$attributes,
        ]);
    }
}
