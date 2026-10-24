---
audience: dev
type: howto
status: living
---

# Расширение площадки

Площадка в данных и crawl-capability — разные вещи. `Platform`/`Registry` можно завести для
справочника без кода; обход становится доступен только после exact definition в
`config/crawler.php` для `Platform.code`.

## Контур расширения

1. Создайте `Platform` и `Registry` в данных. Одинаковый `Registry.code` допустим у разных
   площадок, но пара `(platform_id, code)` уникальна.
2. Добавьте typed definition в `config/crawler.php` → `crawler.platforms`: supported source method
   и nested registry filter/form, `listing_mapper`, `detail_parser`, `detail_source_method`,
   `baseline_path`, `block_size` (1–1000). `PlatformAdapterRegistry` — единственный reader этой карты.
3. Поместите source-specific adapter в `app/Services/Platforms/<Code>/`; текущий descriptor живёт в
   `app/Dto/Procurement/Crawler/PlatformAdapterDefinition.php`, а Filament form — в
   `app/Filament/Platforms/<Code>/`. Не добавляйте branch в factory, model, repository или generic Filament form.
4. Добавьте status mapping как platform default либо exact registry override. Exact registry имеет
   приоритет; неизвестный raw stage создаёт только exact-registry `unknown`.
5. Скопируйте synthetic matrix из `tests/Feature/Procurement/PlatformExtensionContractTest.php`:
   две площадки с одинаковым registry code должны резолвить разные filter/source-method classes,
   а missing platform, method или filter — завершаться explicit fail-closed exception.

Проверки текущего candidate выполняются в isolated harness из repository root:

```bash
make core-test-up
make core-test-env
make core-test-install
make core-test-unit
make core-test-regression
make core-quality
make core-test-report
```

Контракты команд и evidence описаны в [runbook стенда](runbooks/process-test-rig.md).
Исторический `docker/tests/platform-extension-contract.sh` привязан к старому окружению и plan views;
он не является интерфейсом текущей приёмки. Не используйте `|| true` или `--fix`:
любой exit code остаётся красным. `core-test-platforms` получает domain assertions в P2.9–P2.12;
до этого он явно возвращает `suite_not_implemented`, а сохранённые platform cases входят в Unit/Feature.

Откат migration status mappings отказывается до изменения схемы, если существуют platform-scoped
rules. Диагностика missing mapping называет platform/registry/method, а отказ rollback говорит,
что данные platform applicability нельзя удалить без потери. Наблюдайте эти сообщения в тесте и
в операторском интерфейсе, а не подменяйте их fallback. Архитектурный test сканирует весь product
source и разрешает source literals только в exact adapter namespace, descriptor, config и seed
boundary; deliberate forbidden-core fixture обязан оставаться красным.

## Где лежит код площадки

Площадка занимает `Platforms/<Code>` в каждом слое: `app/{Services,Dto,Enums,Models,Filament}`,
`database/{factories,seeders}`, `tests/{Unit,Feature,Fixtures,Support}`. Namespace совпадает с путём.
Сервисные подкаталоги ограничены одним уровнем: `Card`, `Csv`, `Listing`, `Status`, `Documents`,
`Filters`, `Validators`, `Diagnostics`, `Persistence`. Form/schema компоненты лежат за пределами
Filament resource discovery (`app/Filament/Resources`); отдельные Resources/Pages площадки не создаются.

P4.2 переносит классы ЕИС без переименования и изменения behavior. `EisDetails` сохраняет
`#[Table(name: 'prc_eis_details', key: 'procurement_id')]` и явный `UseFactory` на
`Database\Factories\Platforms\Eis\EisDetailsFactory`. Импорт этой модели в baseline migration
обновлён механически; DDL и таблица прежние. `DatabaseSeeder` продолжает вызывать `BaseSeeder`,
который импортирует перенесённый `EisPlatformSeeder`.

Точный физический перенос и решения по смешанным тестам сохранены в
`tests/Fixtures/ProcessCore/platforms-move-map.json`. Чистые parser/enum/clock tests переехали;
row-mapper, URL builders, reliability и seeder integration tests с ядровыми моделями остались
на месте по критерию import/SUT. Названия и assertions старых сценариев сохранены. Исходный
manifest хранит original path/hash/FQCN рядом с текущим значением для сравнения в P4.3;
неизменённые fixture bytes проверяются по исходному SHA-256.
`tests/Fixtures/ProcessCore/original-executed-scenarios.json` сохраняет actual 2014 before cases
с dataset labels и candidate/artifact hashes. Полный regression строит `scenario-comparison.json`:
сопоставляет каждый исходный file/name через точную move map, требует passed status и отсутствие
дублей. Новые guards добавляют cases; равенство общего test count не требуется. Наличие файла
без исполнения одного из его сценариев проверку не проходит.

`PlatformsDomainLayoutTest` (T33) проверяет layout, imports/absolute FQCN/literal class strings,
направление зависимостей, отсутствие старых FQCN и сокращение baseline. Cross-platform import
запрещён даже через baseline. `tests/Fixtures/Platforms/registry.php` — явный fixture registry до
введения runtime definitions. Negative source fixtures создаются в test-owned tmp и проверяют
новую forbidden pair, исчезнувшую baseline pair, escaping, group use и границы integration roles.

Постоянные роли перечислены exact paths/imports в `Tests\Support\Architecture\PlatformSourceRules`:
extension model может ссылаться на Procurement, platform form читает только разрешённые модели
и Okpd2NodeReadRepository, synchronizer сохраняет существующие catalogue repositories. Factories,
seed composition и schema model binding имеют отдельные exact permissions. UI process writes
проверяются отдельно; import graph сам по себе не доказывает transactional ownership.
Транспортные и acquisition зависимости остаются в sorted
`tests/Unit/Architecture/platforms-dependency-baseline.php` с owning items P2.6/P2.12/P2.18/P3.17.
Новая пара запрещена; исчезнувшую надо удалить из baseline. Reference/UI permissions не дают
остальным parsers права импортировать все Models/Repositories.
Поле `dependency_origin` в move map фиксирует 50 пар и owners из commit P4.2. Отдельный
`PlatformsBaselineOriginTest` запрещает расширить baseline одновременно с добавлением import,
переназначить owner или спрятать permanent role в legacy exceptions. Удаление пар разрешено;
P3.17 удаляет сам baseline при своём cutover. Origin inventory остаётся evidence переноса.

Текущие runtime config keys остаются в `config/crawler.php`. Typed platform definitions и перенос
`eis.*` в `config/platforms/eis.php` принадлежат P2.9–P2.12. При том переносе registry хранится
под `platforms.definitions`: top-level code `eis` был бы перезаписан вложенным config.
Нейтрализацию legacy DTO и переключение consumers выполняют их owning cutovers.

## Контракт данных адаптера

`ListingRowMapper` возвращает снимок закупки, `SkippedListingRow(reason)` для корректной
непредметной строки либо `null` для неразобранной строки. Последний исход сохраняет курсор.
Schema-aware mapper объявляет обязательные колонки через `ListingSchemaAware`.

`DetailParser` возвращает нормализованные common-info/documents/journal/results snapshots;
доменная запись остаётся общей. `EisDetailParser` объединяет существующие парсеры ЕИС.
Эталон выбирается по реестру и проверяется на совпадение platform code. Его snapshot повторно
используется для preflight и диагностики одной карточки. Неверный селектор означает ошибку
probe, а законно пустые documents должны сохранять обязательный контейнер.

Площадка задаёт общие capabilities, `registries.<code>` переопределяет mapper/parser/baseline/
размер блока/метод карточки для конкретного реестра. Подстановка адаптера ЕИС отсутствует.
`ListingBatch.observedAt` — время начала HTTP-запроса, до ожидания ответа и блокировок БД;
новый транспорт обязан передавать его для порядка дневных снимков. Source timezone используется
адаптером для построения `sourceUpdatedBefore`; сохранённый интервал не пересчитывается при
последующем изменении настройки timezone.

Пример совместного исполнения двух несовместимых адаптеров находится в
`tests/Unit/Crawler/PlatformAdapterRegistryTest.php`. Общие бюджеты независимы по хосту;
атомарный claim слота защищён короткой блокировкой в том же cache store, что использует limiter.
