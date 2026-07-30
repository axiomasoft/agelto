<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Служебная площадка «Обучалка» (§1.3 root/document-contour.md, D91) — единственный способ,
 * которым площадка появляется в чистой БД (Implementation Rule 3, P1.6): заводить её миграцией
 * запрещено — отменило бы свойство §1.1 («заведение площадки — чистые данные»). Инфраструктурные
 * данные нужны и в prod (обучающий импорт — `estimate:gold:import`/`estimate:dataset:import` — пишет
 * `platform_id` этой строки), поэтому вызывается в `DatabaseSeeder` ДО local/testing-гейта, как
 * `AiRoutingSeeder`/`PromptSeeder`/`StatusMappingSeeder`.
 *
 * Идемпотентен: `updateOrCreate` по `code` (unique).
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
