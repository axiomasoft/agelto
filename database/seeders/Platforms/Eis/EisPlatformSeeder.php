<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\Card\EisCardCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Площадка ЕИС (`zakupki.gov.ru`) и реестр `fz44` с настройками детальной карточки
 * (`procedure_url_types`/`card_url_templates`, `EisCardUrlBuilder`) — до этого сидера площадки
 * ЕИС в проекте не было вовсе (`PlatformSeeder` заводит только служебную «Обучалку»), а
 * `prc_registries.settings` не заполнены нигде: без них детальная стадия падает
 * `CardUrlTemplateMissingException` на первом же запросе карточки.
 *
 * Значения `settings` — живая разведка P4.1, СЫРЫЕ метки `methodOrderPurchaseCsv` → сегмент URL
 * (`artifacts/eis-registry-settings.json`, 261 сопоставление без конфликтов, 2026-08-04) — заведены
 * здесь буквально, второго носителя того же перечня в рантайме нет.
 *
 * Идемпотентен: `updateOrCreate` по `code` площадки (unique) и `(platform_id, code)` реестра
 * (unique-индекс миграции); повторный прогон обновляет `settings`/`name`, а не дублирует строки.
 * `uuid` площадки — явно: `DatabaseSeeder` несёт `WithoutModelEvents`, `creating`-хук `Platform`
 * здесь не срабатывает (тот же приём, что `PlatformSeeder`).
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

    /**
     * СЫРАЯ метка `methodOrderPurchaseCsv` → сегмент URL карточки (`ea20`/`zk20`/…) — НЕ значение
     * `App\Enums\Platforms\Eis\EisProcedureType` (тот резолвится из этой же метки отдельно,
     * `EisCsvRowMapper::mapProcedureType()`). `artifacts/eis-registry-settings.json`
     * `procedure_url_types`, разведка 2026-08-04.
     *
     * @return array<string, string>
     */
}
