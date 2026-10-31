---
audience: dev
type: howto
status: living
---

# Расширение площадки

## Definition и policy (P2.9)

Новый контракт объявляется один раз в `config/platforms.php` под `definitions`:
`code => PlatformDefinitionProvider::class`. Вложенный `config/platforms/<code>.php`
содержит policy; top-level ключ с кодом площадки в `platforms.php` запрещён.
T38 проверяет настоящий `config:cache`: обе ветви остаются доступны.

`PlatformRegistry` создаёт immutable `PlatformDefinition` только из config. Provider
имеет либо пустой constructor, либо единственную зависимость
`Illuminate\Contracts\Config\Repository`; registry не разрешает произвольный container graph.
Конструктор и `definition()` provider обязаны быть чистыми. Reflection не доказывает
отсутствие произвольных side effects в PHP: это проверяется contract tests зарегистрированных
providers, перехватом DB/HTTP и review. Advertised implementations проверяются без создания
объектов; отсутствующий класс, интерфейс вместо реализации и несовместимый контракт дают ошибку.
Definition validation не выполняет catalog sync, migrations, DNS или source requests.

`EisDefinition` собирает `EisPolicy`: block size строго integer 1..1000, timezone из IANA
и полный endpoint RequestProfile. Неизвестные ключи и coercion scalar types запрещены;
ошибка содержит config path. Network сохраняет единственное владение rate/permit/retry/TLS.
Новая policy не копирует origin budget или TrafficClass policy в адаптер.

`RegistryDefinitions` отвергает повтор code и повтор primary identity namespace внутри площадки.
ЕИС явно объявляет `fz44 → registry:44fz`; testing-only JsonProcurement использует
`fz44 → opaque:notice`. `SourceIdentity` состоит из platform/type/value. Opaque value сохраняет
нули, регистр, пробелы и Unicode representation; invalid UTF-8, NUL, пустая строка и длина
больше 200 символов запрещены. Проверка identity всегда получает конкретный request registry.
DTO codec сохраняет ровно три строки, без alias lookup и cross-platform merge.

Проверка деклараций: `php artisan platform:validate [code]`. Команда проверяет весь registry,
затем выбранный code; она не запускает fixtures/Pest и не синхронизирует DB. Отсутствующий
code возвращает failure. UI descriptor проверяется против `PlatformUiExtension`; extension
descriptor — против readonly DTO и `ExtensionPersister`, с exact fully-qualified DTO в
PHPDoc generic `@implements`. Adapter/parser version обязаны совпадать. Writer constructor
при проверке не вызывается. Транзакционный writer и UI activation принадлежат своим cutovers.

Граница staging: P2.10 объявляет Supported listing/detail для EIS и JSON и partitioning для EIS.
Документы и status остаются явно Unsupported до owning implementations. P2.10 поставляет
fetch/results, validator/cursor/filter contracts; P2.11 — общий fixture kit. P2.12 подключает
Definition к catalog sync, CLI validation и optional UI resolver. По уточнённой границе cutover
`PlatformAdapterRegistry`, `SourceMethodFactory`, `PlatformAdapterDefinition` и `crawler.platforms`
обслуживают только действующие acquisition consumers до их атомарного переключения в P2.18.
Новые consumers используют PlatformRegistry; compatibility descriptor и второй state owner не вводятся.
Схема identity и CommonInfoProcessor/ProcurementDetails меняются в acquisition cutover.
JSON теперь разбирает собственный JSON layout, opaque identity/cursor и один detail endpoint.

Адресные assertions находятся в
`tests/Feature/ProcessCore/Platforms/T38PlatformBootValidationTest.php` и `T43EisPolicyTest.php`.
P2.10 добавляет `T39AdapterResultsTest.php` (groups t02/t27/t39/p22). Он не доказывает
durable acquisition/finalizer, T41/T42/T45 или полноту следующего среза.

## Neutral results и планы запросов (P2.10)

`ListingSource::fetch` / `DetailSource::fetch` получают typed request и ExecutionContext.
`PlatformOutbound` — узкий порт к существующему `OutboundExecutor`; его
`ExecutorPlatformOutbound` передаёт неизменный OutboundRequestPlan, gate, URL policy и budget.
Retry, redirect, leases, health и persistence не принадлежат парсеру. Composition передаёт
проверенный target UUID из каталога. Catalog activation не запускает новые acquisition handlers.

Результат — ListingFetched/DetailFetched либо FetchDeferred/FetchBlocked/FetchItemRejected.
Deferred сохраняет точный RetryAt; отсутствие результата не превращается в пустую карточку.
Невалидный listing возвращает Partial assessment без next cursor и terminal proof. Assessment
содержит reason, failureScope, confidence, body digest и optional artifact reference.
Raw тела не входят в canonical snapshot. ResponseValidator проверяет тот же parser result
относительно ExpectedResource, а не только HTTP 200.

ЕИС использует immutable EisListingFilter с уже разрешёнными platform-local OKPD2 IDs.
EisListingCursor содержит день, выбранную дату, inclusive цены в копейках, first row и filter hash;
только адаптер декодирует его. Запросы начинают с 1; to = from + blockSize − 1.
Фильтр стадии, дата и цена проверяются по строкам, comparative ListingFilterCheck оценивает
изменение выборки. Это bounded диагностическое свидетельство, а не доказательство source-side
семантики OKPD2 для каждой строки. Scope неизвестных цен не объявляется покрытым.

CSV header-only — semantic empty; blank, неверная ширина/quoting, дата, identity или price —
не terminal. rowsTotal считает записи до dedup и mapping. Лимиты CSV остаются 1000 records,
262144 bytes/field, 256 columns и 128000 separators; parser deadline и ExecutionContext tick
проверяются во время чтения. `platforms.eis.listing_bounds` задаёт локальные resource bounds,
не source SLA. Повтор digest или исчерпание blocks даёт incomplete; 5000 не является cap proof.
Price split создаёт [lo,mid] и [mid,hi]; depth/partition/min-interval bound возвращает явную
причину incomplete. Создание required successors и сохранение debt выполняет P2.15.

ProcurementSummaries сортирует normalized observations для digest, сохраняя cardinality.
ListingEvidence сравнивает полные последовательные passes одной immutable partition:
два одинаковых — Stable, open — ObservedOpen, три несовпадающих — Incomplete. Это чистая
оценка evidence; durable membership/full-pass aggregation и watermarks принадлежат acquisition.
Offset listing не обещает абсолютную полноту изменяемого источника.

DetailRequest может передавать opaque locator из ProcurementSummary. ЕИС переносит исходную
метку способа закупки в `eis.procedure`; EisCardRoutes получает exact label→procedure и
procedure→tabs из доверенного registry snapshot. Missing mapping — явный отказ, fallback нет.
Обязательные common-info/documents и явно объявленные optional tabs дают нейтральные parts
attributes/parties/documents/events/results. Part содержит hash и fetchedAt. Несовпавший
точный номер карточки rejected; отсутствующая required section оставляет snapshot partial.
Strict parser path не маскирует programming Throwable; legacy signatures сохраняют своё
поведение до атомарного переключения потребителей.

EisDetailExtension содержит специфичные identifiers/IKZ/ETP/contact fields. Контракт
`ExtensionPersister<TExtension>` и pairing descriptor уже определены; concrete writer
и его activation выполняет P2.14. Сейчас нет второго writer `prc_eis_details`.
ProcurementDetails/CommonInfoData/CommonInfoProcessor остаются legacy carriers до P2.14,
как требует cutover; нейтральный путь использует ProcurementSnapshot.

Testing-only JsonProcurement использует epoch seconds, opaque ключи (включая пробелы и регистр),
`notices/paging` для listing и `notice/participants/attachments` для одной detail response.
JSON не содержит CSV headers. Unknown/missing keys, неверные типы, count/end/cursor conflicts
и identity mismatch отвергаются. Signed document references сохраняют expiry; разрешение
и обновление истёкших ссылок принадлежит document access capability, пока Unsupported.
Fixture hashes и вручную заданные ожидания находятся в process-core manifest.

## Offline contract kit (P2.11)

`tests/Support/Platforms/Contract/ContractRegistry.php` — единственная test composition точка:
регистрирует `EisContractManifest` и `JsonContractManifest`. `AdapterContractKit` получает реальные
Definitions из PlatformRegistry и требует manifest для каждой. Новая площадка добавляет свой
manifest и регистрацию; общий validator не знает CSV, DOM, кодов площадок или их URL.
Exact architecture permission разрешает registry только эти manifests; нейтральный kit по-прежнему
не может импортировать адаптер. Площадочные manifests могут использовать общие contract helpers.

Обязательные semantic cases выводятся из Supported capabilities: listing valid/empty/truncated/
overflow/loop/identity determinism; detail valid/required sections/wrong identity/status/redaction;
partitioning split/bounds. Unsupported status/document access не требуют несуществующих fixtures.
Их runtime invocation остаётся fail-closed обязанностью capability dispatcher при activation.
Площадочный manifest может потребовать дополнительные случаи: ЕИС blank/login, JSON wrong total.
Удаление любого обязательного case или fixture, отсутствие manifest, duplicate case и stale input
hash дают ошибку до semantic execution. Golden observations написаны отдельно от parser results:
exact identity, title, raw source stage, decimal price, terminal/cursor и required/optional parts.
Проверка raw stage здесь не заменяет T40 status mapping/remap следующего acquisition среза.

Response corpus и SHA-256/provenance зарегистрированы в `tests/Fixtures/ProcessCore/manifest.json`.
`tests/Fixtures/Platforms/Eis/Card/parser-scenarios.json` фиксирует 133 именованных сценария в 13
сохраняемых parser/clock/URL/legacy transport test files; hashes защищают также datasets и assertions.
Это доказательство сохранности, а не новый pass всей legacy regression. Windows-1251 и quoted
newlines сохраняются в прежних tests; новый T39 дополнительно исполняет BOM и точные parser bounds.
Negative mutation создаёт отдельный in-memory вариант настоящего EisListingSource без overflow
guard: тот же golden case обязан его отвергнуть. Рабочие product files не мутируются.

`FetchOutcomeBridge` — чистая граница failure/partial для будущих handlers. Он сохраняет RetryAt:
no_route/rate_limit/capacity_wait/slice_exhausted остаются одноимёнными reasons;
status quota → rate_limit; exhausted attempts/dependency unavailable → target_cooldown.
AdapterHeld → handler_hold; configuration/credential/retired/URL-policy outcomes → policy_review;
OwnerLost прерывает выполнение через LeaseLost. WorkFinalizer остаётся единственным владельцем
budget/state и может добавить штатный неотрицательный jitter к RetryAt.

Schema/layout assessment не расходует item budget и становится blocked(handler_hold). Только
item-scoped rejection с сохранённым artifact и совпадающим body hash даёт StageFailedItem.
Handler должен сохранить bytes и прикрепить ArtifactRef до bridge; digest сам по себе не artifact.
ЕИС отсутствие identity selector (login/changed layout) даёт partial section; явно чужой номер —
item rejection. Partial принимает durable checkpoint вызывающего handler и перечисляет missing
required parts. Complete result требует собственного validated publication; bridge его не создаёт.

`T39FetchOutcomeBridgeTest` проводит EIS/JSON fetch через настоящий WorkRunner/PG finalizer:
no-route, exhausted, rate, held, truncated listing, required detail/schema и три genuine rejects.
Проверяются state/reason/due, отдельные counters, отсутствие canonical publication и четвёртого
fetch после exhaustion. Outbound здесь deterministic recording port: эти тесты не заявляют новых
HTTP/concurrency доказательств. Новый bridge пока не активирует acquisition runtime.

Fixture registry и реальные definitions согласованы при catalog activation;
acquisition/UI baseline сохраняется до owning cutovers. P2.14 использует bridge с настоящими
artifact persistence, checkpoints и publication contracts; ledger не переносится в адаптер.

## Каталог и optional UI seam (P2.12)

`platform:sync-catalog` получает procurement targets только из `platforms.definitions`.
`outbound.catalog_providers` предназначен для non-procurement provider catalogs; platform catalog
в этом списке отвергается. Команда выполняется явно после migrations, до запуска workers.
Routine boot и `platform:validate` не выполняют DB/network I/O. Invalid или missing definition
возвращает failure; fixture corpus запускается отдельно, без зависимости production CLI от Pest.

EisDefinition сохраняет действующие target keys `csv`, `card`, `okpd2`, `document.<origin hash>`
и adapter bindings listing/detail. Diagnostics получает отдельный bounded target. Default profiles
берутся из `platforms.eis`; reference endpoint и дополнительные document hosts пока читаются
при сборке Definition из `crawler.okpd2.children_endpoint` и `crawler.documents_allowed_hosts`.
Это config-only декларации для сохраняемых consumers, не дополнительный catalog provider.
При изменении legacy CSV/card URL до P2.18 соответствующие `platforms.eis.endpoints` меняются
согласованно. Идемпотентный sync сохраняет overrides, debt, health и policy versions; aliases
одного origin разделяют budget. Retired targets не оживляются и не получают новых grants/sends.

`PlatformUiResolver::resolve(PlatformCode)` возвращает optional `PlatformUiExtension` из Definition.
Отсутствующее расширение возвращает null; неизвестный code и несовместимый DI binding отвергаются.
Общие sections дополняются через `extension?->profileFormSchema() ?? []` или
`extension?->procurementInfolistSections($procurement) ?? []`. Validator не создаёт UI components.
Полная интеграция EIS sections в Resources и T48 остаются в P3.16–P3.19; сейчас EIS/JSON не
объявляют UI extension. Никакого table extras API или hardcoded relation не добавлено.

`T45SourceCatalogSyncTest` в Platforms проверяет actual EIS/JSON definitions, repeat sync,
overrides, retirement до claimSend и отказ нового permit. Существующий Network/T45 сохраняет
machinery cases. `PlatformUiExtensionTest` проверяет optional seam и CLI без I/O.

## Сохраняемый acquisition runtime до P2.18

Площадка в данных и crawl-capability — разные вещи. `Platform`/`Registry` можно завести для
справочника без кода; обход становится доступен только после exact definition в
`config/crawler.php` для `Platform.code`.

### Legacy registration для действующих consumers

1. Создайте `Platform` и `Registry` в данных. Одинаковый `Registry.code` допустим у разных
   площадок, но пара `(platform_id, code)` уникальна.
2. Сохраняемая registration map в `config/crawler.php` → `crawler.platforms`: supported source method
   и nested registry filter/form, `listing_mapper`, `detail_parser`, `detail_source_method`,
   `baseline_path`, `block_size` (1–1000). `PlatformAdapterRegistry` — единственный reader этой карты.
3. Поместите source-specific adapter в `app/Services/Platforms/<Code>/`; текущий descriptor живёт в
   `app/Dto/Procurement/Crawler/PlatformAdapterDefinition.php`, а Filament form — в
   `app/Filament/Platforms/<Code>/`. Не добавляйте branch в factory, model, repository или generic Filament form.
4. Добавьте status mapping как platform default либо exact registry override. Exact registry имеет
   приоритет; неизвестный raw stage создаёт только exact-registry `unknown`.
5. Сохраняется synthetic matrix из `tests/Feature/Procurement/PlatformExtensionContractTest.php`:
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
любой exit code остаётся красным. `core-test-platforms` с P2.9 запускает текущие T38/T43
из `tests/Feature/ProcessCore/Platforms` и platform architecture checks через isolated harness
после guarded clean reset. Empty/skipped selection остаётся ошибкой. Адаптерные assertions
добавляются owning пунктами P2.10–P2.12; текущий pass не подтверждает будущие механизмы.

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
`tests/Unit/Architecture/platforms-dependency-baseline.php` с owning items P2.6/P2.18/P3.17.
Новая пара запрещена; исчезнувшую надо удалить из baseline. Reference/UI permissions не дают
остальным parsers права импортировать все Models/Repositories.
Поле `dependency_origin` в move map фиксирует 50 пар и owners из commit P4.2. Отдельный
`PlatformsBaselineOriginTest` запрещает расширить baseline одновременно с добавлением import,
произвольно переназначить owner или спрятать permanent role в legacy exceptions. Единственное
согласованное изменение owners — исходные P2.12 → P2.18 для сохраняемых acquisition dependencies.
Все 12 таких пар ещё используются, поэтому удаление их сейчас было бы ложным shrink. Удалены
устаревшие exact catalog permissions для config/outbound.php и CsvSourceMethod; новые baseline
пары не добавлены. Удаление исчезнувших пар обязательно;
P3.17 удаляет сам baseline при своём cutover. Origin inventory остаётся evidence переноса.

Текущие acquisition runtime config keys остаются в `config/crawler.php` до атомарного P2.18 cutover.
P2.9 добавляет config-only definitions и `platforms.eis` для нового контракта; существующие
readers переключаются вместе с адаптерами. Registry хранится под `platforms.definitions`:
top-level code `eis` был бы перезаписан вложенным config.
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
атомарный claim слота выполняется единственным PostgreSQL NetworkPermitRepository.
