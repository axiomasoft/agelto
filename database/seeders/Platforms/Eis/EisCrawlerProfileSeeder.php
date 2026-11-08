<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Deterministic scoped acquisition profiles. Fresh scheduling remains disabled until explicitly enabled. */
final class EisCrawlerProfileSeeder extends Seeder
{
    public function run(): void
    {
        $registry = $this->resolveEisFz44Registry();

        $this->seedArchive2025Profile(registry: $registry);
        $this->seedArchive2026Profile(registry: $registry);
        $this->seedFreshProfile(registry: $registry);
    }

    /**
     * Резолв по паре (площадка `eis`, код `fz44`) — не `Registry::where('code', …)->first()`:
     * код `fz44` не уникален глобально (заводится фабриками у сторонних площадок), поэтому поиск
     * без площадки достаётся первому попавшемуся реестру. Неоднозначность (два реестра `fz44` у
     * ОДНОЙ площадки `eis`) — тоже `RuntimeException` с перечислением id: молча брать первый
     * значит воспроизводить тот же класс отказа, который здесь чинится.
     */
    private function resolveEisFz44Registry(): Registry
    {
        $platform = Platform::query()->where('code', 'eis')->first();

        if (! $platform instanceof Platform) {
            throw new RuntimeException(
                'CrawlerProfileSeeder: площадка eis не найдена — прогоните EisPlatformSeeder первым.'
            );
        }

        $registries = Registry::query()->where('platform_id', $platform->id)->where('code', 'fz44')->get();

        if ($registries->count() > 1) {
            throw new RuntimeException(sprintf(
                'CrawlerProfileSeeder: у площадки eis найдено %d реестров с кодом fz44 (id: %s) — неоднозначность.',
                $registries->count(),
                $registries->pluck('id')->implode(', '),
            ));
        }

        $registry = $registries->first();

        if (! $registry instanceof Registry) {
            throw new RuntimeException(
                'CrawlerProfileSeeder: реестр fz44 площадки eis не найден — прогоните EisPlatformSeeder первым.'
            );
        }

        return $registry;
    }

    private function seedArchive2025Profile(Registry $registry): void
    {
        Crawler::query()->updateOrCreate(
            ['registry_id' => $registry->id, 'name' => 'ЕИС 44-ФЗ — архив 2025 (завершённые)'],
            [
                'listing_kind' => AcquisitionKind::Archive,
                'filter' => new EisSearchFilter(
                    stageCompleted: true,
                    priceTo: 1_000_000_000_000,
                    okpd2Codes: ['43'],
                    publishedFrom: CarbonImmutable::parse('2025-01-01'),
                    publishedTo: CarbonImmutable::parse('2025-12-31'),
                    sortField: ListingSortField::Published,
                    sortAscending: true,
                ),
                'schedule' => null,
                'is_active' => true,
                'route_profile_id' => null,
            ],
        );
    }

    private function seedArchive2026Profile(Registry $registry): void
    {
        Crawler::query()->updateOrCreate(
            ['registry_id' => $registry->id, 'name' => 'ЕИС 44-ФЗ — архив 2026 (завершённые)'],
            [
                'listing_kind' => AcquisitionKind::Archive,
                'filter' => new EisSearchFilter(
                    stageCompleted: true,
                    priceTo: 1_000_000_000_000,
                    okpd2Codes: ['43'],
                    publishedFrom: CarbonImmutable::parse('2026-01-01'),
                    publishedTo: CarbonImmutable::parse('2026-12-31'),
                    sortField: ListingSortField::Published,
                    sortAscending: true,
                ),
                'schedule' => null,
                'is_active' => true,
                'route_profile_id' => null,
            ],
        );
    }

    private function seedFreshProfile(Registry $registry): void
    {
        Crawler::query()->updateOrCreate(
            ['registry_id' => $registry->id, 'name' => 'ЕИС 44-ФЗ — свежий поток'],
            [
                'listing_kind' => AcquisitionKind::Fresh,
                'filter' => new EisSearchFilter(
                    priceTo: 1_000_000_000_000,
                    okpd2Codes: ['43'],
                    sortField: ListingSortField::Updated,
                    sortAscending: false,
                ),
                'schedule' => '*/30 * * * *',
                'is_active' => false,
                'route_profile_id' => null,
            ],
        );
    }
}
