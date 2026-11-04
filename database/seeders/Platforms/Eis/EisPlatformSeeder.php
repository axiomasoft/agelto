<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\Card\EisCardCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Площадка ЕИС (`zakupki.gov.ru`) и реестр `fz44` с настройками детальной карточки.
 * `EisCardCatalog` задаёт соответствие сырых меток `methodOrderPurchaseCsv` сегментам URL
 * и шаблоны вкладок по типу процедуры. Без этих настроек legacy builder не разрешает URL.
 *
 * Повторный запуск обновляет строку по уникальному `code` площадки и `(platform_id, code)`
 * реестра, сохраняя существующие identities. UUID задаётся явно:
 * `DatabaseSeeder` использует `WithoutModelEvents`, поэтому creating-hook не вызывается.
 */
final class EisPlatformSeeder extends Seeder
{
    private const string PLATFORM_CODE = 'eis';

    private const string REGISTRY_CODE = 'fz44';

    public function run(): void
    {
        $platform = $this->seedPlatform();

        $this->seedRegistry($platform);
    }

    private function seedRegistry(Platform $platform): void
    {
        $attributes = [
            'name' => '44-ФЗ — Контрактная система в сфере закупок товаров, работ, услуг',
            'settings' => [
                'procedure_url_types' => (new EisCardCatalog)->procedureUrlTypes(),
                'card_url_templates' => (new EisCardCatalog)->cardUrlTemplates(),
            ],
        ];

        $existing = Registry::query()
            ->where('platform_id', $platform->id)
            ->where('code', self::REGISTRY_CODE)
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        Registry::query()->create([
            'platform_id' => $platform->id,
            'code' => self::REGISTRY_CODE,
            'uuid' => (string) Str::uuid7(),
            ...$attributes,
        ]);
    }

    private function seedPlatform(): Platform
    {
        $attributes = [
            'name' => 'Единая информационная система в сфере закупок (ЕИС)',
            'base_url' => 'https://zakupki.gov.ru',
            'is_active' => true,
            'is_public' => true,
        ];

        $existing = Platform::query()->where('code', self::PLATFORM_CODE)->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        // `uuid` — явно: `DatabaseSeeder` несёт `WithoutModelEvents`, `Platform::booted()`
        // (`creating`-хук) здесь не срабатывает (тот же приём, что `PlatformSeeder`).
        return Platform::query()->create([
            'code' => self::PLATFORM_CODE,
            'uuid' => (string) Str::uuid7(),
            ...$attributes,
        ]);
    }
}
