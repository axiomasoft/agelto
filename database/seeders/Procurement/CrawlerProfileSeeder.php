<?php

declare(strict_types=1);

namespace Database\Seeders\Procurement;

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Два профиля обхода реестра `fz44` — архивный (обучающий корпус, `research/05-change-tracking.md`
 * §5.2) и fresh (продакшен-слежение, §5.3). До этого сидера ни одного профиля ЕИС в проекте не
 * было — без них `crawler:run-archive`/`crawler:run-fresh` нечего запускать.
 *
 * `options.columns` — общая часть (§5.1): ровно те 12 флагов, чьи заголовки реально читает
 * `EisCsvRowMapper`, плюс `registryNumberCsv` — билдер добавляет его принудительно независимо от
 * профиля (`EisCsvUrlBuilder::REGISTRY_NUMBER_COLUMN_FLAG`), здесь дублируется для читаемости
 * формы (Implementation Rules P4.5 п. 1: значение — из таблиц `research/05`, не выдумано).
 *
 * Идемпотентен: `updateOrCreate` по натуральному ключу `(registry_id, name)` — повторный прогон
 * обновляет профиль, отредактированный сидом, а не создаёт дубль. Оператор, отредактировавший
 * профиль в админке, рискует потерять правку при повторном `db:seed` — сид перезаписывает
 * безусловно (тот же приём, что `StatusMappingSeeder`/`AiRoutingSeeder`: инфраструктурные сиды
 * этого плана не мержат частично, приём документируется здесь явно).
 *
 * fresh-профиль заводится `is_active=false` (D37): `routes/console.php` регистрирует АКТИВНЫЕ
 * fresh-профили в расписание на КАЖДОМ artisan-вызове — активный сид означал бы автозапуск сразу
 * после `db:seed`. Включение — отдельное решение владельца после P4.7.
 */
final class CrawlerProfileSeeder extends Seeder
{
    /**
     * Общие 11 флагов колонок (`research/05-change-tracking.md` §5.1) + принудительный
     * `registryNumberCsv` — единственный носитель этого перечня в сиде. `scopeOkpd2Csv` снят
     * (D16, P2.6): колонка «Классификация по ОКПД2» приходит пустой, запрашивать её — трата
     * места в выгрузке.
     *
     * @var array<string, bool>
     */
    private const array COLUMNS = [
        'registryNumberCsv' => true,
        'publishDateCsv' => true,
        'lastDateChangeCsv' => true,
        'stepOrderPlacementCsv' => true,
        'methodOrderPurchaseCsv' => true,
        'nameOrderCsv' => true,
        'purchaseNumbersCsv' => true,
        'maxContractPriceCsv' => true,
        'currencyCodeCsv' => true,
        'startDateRequestCsv' => true,
        'endDateRequestCsv' => true,
        'customerNameCsv' => true,
    ];

    public function run(): void
    {
        $registry = $this->resolveEisFz44Registry();

        $this->seedArchiveProfile(registry: $registry);
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

    /**
     * Профиль 1 — архив (`research/05` §5.2): «завершённые закупки за 2025», окно дат живёт НА
     * ПРОФИЛЕ (`filter.publishedFrom/publishedTo`, D113/P3.8) — `crawler:run-archive` без явных
     * `--from`/`--to` читает его через контракт `HasArchiveWindow`. Сортировка — по дате размещения
     * ПО ВОЗРАСТАНИЮ (`sortField: Published`, `sortAscending: true`): обучающий корпус читается
     * от старых закупок к новым, `stop_policy = Default` — политика «только актуальные данные»
     * (P3.3) осмыслена лишь у fresh-прогона, архивный её не применяет (`Crawler::assertStopPolicy()`).
     */
    private function seedArchiveProfile(Registry $registry): void
    {
        Crawler::query()->updateOrCreate(
            ['registry_id' => $registry->id, 'name' => 'ЕИС 44-ФЗ — архив 2025 (завершённые)'],
            [
                'execution_lane' => CrawlerExecutionLane::Long,
                'mode' => CrawlerMode::Archive,
                'stop_policy' => RunStopPolicy::Default,
                'source_method' => SourceMethod::Csv,
                'fallback_method' => null,
                'filter' => new EisSearchFilter(
                    stageCompleted: true,
                    okpd2Codes: ['43'],
                    publishedFrom: CarbonImmutable::parse('2025-01-01'),
                    publishedTo: CarbonImmutable::parse('2025-12-31'),
                    sortField: ListingSortField::Published,
                    sortAscending: true,
                ),
                'schedule' => null,
                'options' => ['columns' => self::COLUMNS],
                'is_active' => true,
                'use_proxy' => false,
                'proxy_country' => 'RU',
            ],
        );
    }

    /**
     * Профиль 2 — fresh (`research/05` §5.3): обязан видеть ЛЮБОЕ обновление — этапы по-прежнему
     * не фильтруются, виден любой переход; сужен только предметный скоуп (`okpd2Codes: ['43']`,
     * D18 — фильтр ОКПД2 обязателен для профилей ЕИС). Сортировка — по дате обновления ПО
     * УБЫВАНИЮ (`sortField: Updated`, `sortAscending: false`): свежие изменения обязаны идти
     * первыми, на этом порядке стоит стоп-условие `stop_policy = OnlyFresh` (P3.3) — прогон
     * останавливается, дойдя до времени старта предыдущего завершённого прогона. Курсор без окна
     * дат — `FreshRunProcessor` листает от `from=1` сам.
     */
    private function seedFreshProfile(Registry $registry): void
    {
        Crawler::query()->updateOrCreate(
            ['registry_id' => $registry->id, 'name' => 'ЕИС 44-ФЗ — свежий поток'],
            [
                'execution_lane' => CrawlerExecutionLane::Standard,
                'mode' => CrawlerMode::Fresh,
                'stop_policy' => RunStopPolicy::OnlyFresh,
                'source_method' => SourceMethod::Csv,
                'fallback_method' => null,
                'filter' => new EisSearchFilter(
                    okpd2Codes: ['43'],
                    sortField: ListingSortField::Updated,
                    sortAscending: false,
                ),
                'schedule' => '*/30 * * * *',
                'options' => ['columns' => self::COLUMNS],
                'is_active' => false,
                'use_proxy' => false,
                'proxy_country' => 'RU',
            ],
        );
    }
}
