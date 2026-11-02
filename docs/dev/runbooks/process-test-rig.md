---
audience: dev
type: runbook
status: living
---

# Изолированный process-core стенд

Все команды выполняются из repository root. `make core-test-up` собирает отдельные PHP и
PostgreSQL 17 + pg_partman images и поднимает project `agelto_core_test`. Docker Compose использует
только `docker/compose.core-test.yml` и публичный fixture env `docker/tests/process-core/rig.env`.
Имя project, файл Compose и env-file не переопределяются переменными dev-окружения.
Все stateful core-* вызовы удерживают общий host `flock` в ignored reports directory до
завершения команды и записи evidence. Параллельные make-процессы не могут одновременно
пересоздавать БД, запускать suites или менять состояние workers. Перед проверками необходим
завершившийся `core-test-up`; acceptance текущего execution-input выполняет coordinator
последовательно, без reuse результатов.
`flock` исключает одновременные mutations, но не задаёт порядок зависимых команд; pure Unit suite проверяет identity без требования domain migrations.
Для сборки нужны Docker и исходные установленные `vendor/`; зависимости Composer не добавляются.

Исходники подключаются явно и read-only. Host `.env`, `.env.docker`, `local/`, host storage и dev
volumes не подключаются; versioned env examples доступны только как исходники, а runtime `.env`
содержит публичный fixture env. Runtime storage, Redis, PostgreSQL и MinIO принадлежат named volumes
этого project. Все credentials синтетические. Сеть `agelto_core_test_core` имеет `internal: true`,
портов на host нет. HTTP fixtures, ограниченный HTTP proxy и fake Gemini находятся в этой сети.
CONNECT/TLS и полноценный Gemini protocol получают assertions в своих owning пакетах;
текущие fixture endpoints не считаются доказательством этих контрактов.

| Команда | Текущий контракт harness |
| --- | --- |
| `make core-test-up` | Compose identity, connectivity, healthy app/Postgres/Redis/MinIO |
| `make core-test-env` | Positive/negative target checks, fixture manifest/wire assertions, raw socket egress deny и fixture reachability |
| `make core-test-reset` | Guard, остановка только test workers/scheduler, DROP/CREATE `agelto_test`, migrate, minimal seed, Redis/object/runtime cleanup, readiness, manifests, запуск test workers |
| `make core-test-install` | Два полных reset; точное сравнение normalized schema/seed/extension/partman manifests |
| `make core-test-unit` | Fixture manifest/wire assertions и существующий Unit suite без TIA; zero cases, skips и отсутствие assertions не проходят |
| `make core-test-regression` | Clean reset и весь project PHP suite без TIA; actual JUnit scenario inventory |
| `make core-quality` / `make quality` | Pint `--test`, configured PHPStan level max, Architecture suite и baseline/schema/docs checks |
| `make core-test-report` | Проверка наличия результатов всех текущих gates и совпадения candidate input tree |
| `make core-test-down` | Identity guard, удаление только test project и его volumes |

`make core-test-execution` (P1.6–P1.8): clean reset, затем только
`tests/Feature/ProcessCore/Execution` и `tests/Integration/ProcessCore/Concurrency` на реальном
PostgreSQL rig — ledger/authority, admission/claim/controls, lease/checkpoint/finalize и
многопроцессные гонки. Пустая выборка, skip или отсутствие assertions не проходят.

`make core-test-concurrency` (с P1.8): clean reset, затем только
`tests/Integration/ProcessCore/Concurrency` — отдельные PHP processes и PostgreSQL connections с
детерминированными barriers (holder держит locks, пока другой backend реально не ждёт lock): pause
против claims/finalize, два feeder, finalize против контролируемой generation revocation в обоих
порядках, cancel/new head, lost checkpoint CAS и handler fault budget. Revocation — fixture под тем
же full prefix; настоящий RecoveryReconciler и полные recovery races добавляют P1.11/P1.12.

`make core-test-network` с P2.1 запускает INITIAL machinery subset после guarded clean reset:
`tests/Feature/ProcessCore/Network` и отдельный
`tests/Integration/ProcessCore/Concurrency/T45CatalogConcurrencyTest.php`. На реальном PostgreSQL
проверяются typed contracts, owner all-or-none/FK/CHECK, bounded bridge cleanup и T45:
идемпотентный sync, общий canonical origin aliases, сохранение overrides/rate debt/health epoch,
version fence, retirement tombstone и config-only registry без DB/HTTP I/O. Конкурентный T45
использует отдельные PHP processes/PG connections. Raw fixture demands/permits проверяют DDL,
но не являются работающим actual-send gate. Transport, permit scheduling, health reducers и
переключение старых consumers здесь не объявляются проверенными; их добавляют owning P2.2–P2.7.
JUnit/evidence capture отвергает пустую выборку, skips и отсутствие assertions.

`core-test-observability` (P1.14–P1.18), `core-test-platforms` (P2.9–P2.12) и
`core-test-acquisition` (P2.14–P2.19) реализованы: guarded clean reset, затем только пути
`SUITE_PATHS` из `docker/tests/process-core/rig.py`. Acquisition запускает
`tests/Feature/ProcessCore/Acquisition` и `tests/Unit/Architecture/AcquisitionIdentityOwnershipTest.php`:
T07/T12/T27 listing/detail/coverage, T17/T18 run lifecycle через настоящий `AcquisitionScheduler`
(open/closed empty, shared required, cancel, superseded successor, pending document handoff),
T02 независимость площадок, T40–T42 identity/status/extension, T35 adapter validation и EXR-01
sidebar после circuits cutover. `core-test-documents`, `core-test-ai`,
`core-test-e2e`, `core-test-faults`, `core-test-load WIDTH=10` и `WIDTH=20` пока завершаются
с exit 78 и JSON `suite_not_implemented`. Их реализуют owning P3 packages. Это не green skip.
Browser service отдельный, доступен через профиль `browser`; operator E2E ещё не реализован.
Horizon потребляет только `process-core-fixtures`, scheduler работает с пустым business baseline.
Нет live crawling, paid model eval, production deployment или уведомлений.

## Локальные wire fixtures

Каталог `docker/tests/process-core/fixtures/wire-v1.json` смонтирован read-only в каждый
fixture service. `tests/Fixtures/ProcessCore/manifest.json` содержит его SHA-256, дату,
sanitized provenance, adapter/schema version, ожидаемый assessment и scenario IDs.
Ожидаемые envelopes написаны и проверены вручную. Hash mismatch, отсутствующий файл,
symlink, путь вне repository root и неполные метаданные отвергаются до запуска проверок.

| Fixture | Реальные assertions текущего среза |
| --- | --- |
| HTTP origin | Server-side sequence 503 → 503 → 200, последующие запросы сохраняют последний ответ; конкурентные запросы имеют уникальный sequence |
| HTTP proxy | Физический forward только к `http://fixture-http:8080`; чужой host/IP/port, HTTPS, userinfo и fragment отвергаются до соединения |
| Fake Gemini | Raw HTTP envelopes с exact model JSON, STOP/MAX_TOKENS, usage и provider request IDs; malformed request JSON отвергается |

Счётчик не доверяет client attempt number. Request log ограничен 1024 записями и содержит
case/sequence/method/status/body SHA-256; headers, credentials и raw request body не записываются.
`GET /__fixtures/log` читает log, `POST /__fixtures/reset` сбрасывает log и sequences только fixture service.
CONNECT возвращает 501 `suite_not_implemented`; TLS с локальным CA и полный provider protocol
остаются за owning packages. `verify => false` не используется.

`env` и `unit` entrypoints сохраняют `fixture-manifest.log`, `fixture-tests.log` и
`fixture-proof.json`: hashes и положительное число выполненных тестов без skips.
Wire tests поднимают реальные HTTP servers на loopback и проверяют proxy forwarding;
отдельный Docker raw-socket proof по-прежнему проверяет запрет внешнего egress.
Manifest помечает coverage `fixture_wire_only`: это не pass будущих domain T03/T13/T22 suites.

Узкие проверки без запуска полного suite:

```bash
python3 -B docker/tests/process-core/fixture_manifest.py
python3 -B docker/tests/process-core/test_fixtures.py
python3 -B -m unittest discover -s docker/tests/process-core -p test_rig.py -v
```

## Identity и bootstrap guards

Перед любым DROP/kill/flush helper проверяет resolved Compose config, exact container names и
labels, runtime mounts/networks/env, принадлежность volumes, testing environment и PostgreSQL
identity. SQL maintenance connection обращается только к проверенному контейнеру
`agelto_core_test-core-postgres-1`. Разрешена только `agelto_test`; функция negative guard также
проверяет exact `agelto_test_test_<текущий token>`, но reset CLI не принимает произвольное имя БД.
Неправильный project, похожее имя `another_test`, чужой worker token, external volume или dev bind
останавливают операцию до остановки workers и до DROP. Полное пересоздание БД удаляет и partman
metadata вне `public`; одного `migrate:fresh` для этого недостаточно.
Остановленные принадлежащие стенду workers остаются объектами проверки labels/mounts/env,
но Docker удаляет их network endpoints. Состав сети обязан точно совпадать с running контейнерами
после полной identity-проверки; неизвестный endpoint по-прежнему отвергается.

Identity/connectivity проверяются до миграций, readiness — после. Существующий
`docker/scripts/test-database.sh --phase identity` позволяет preflight пустой БД; обычный вызов
по-прежнему требует отсутствия pending migrations. Этот legacy helper не выполняет core reset.

`tests/bootstrap.php` выставляет значения в `$_SERVER`, `$_ENV` и `putenv()` до autoload.
`PROCESS_CORE_TEST=1` выбирает `core-postgres`, отдельный Redis prefix и проверенный режим очереди
`PROCESS_CORE_QUEUE_MODE=sync|redis`. Integration получает тот же TestCase и guards, но trait
управления транзакциями задаёт конкретный integration test. Serial DB остаётся `agelto_test`,
Laravel parallel callback проверяет exact worker DB до миграций. `TestFilesystemIsolation`
сохраняет local process roots и отвергает symlink до mkdir/cleanup. `Http::preventStrayRequests()`
остаётся дополнительным барьером к Docker network isolation. `phpunit.xml` задаёт serial defaults, затем bootstrap
принудительно синхронизирует три env sources и выбирает rig host/queue. Resolved config проверяется
при boot и перед reset. Не заменяйте эту схему generic советом наследовать dev DB_HOST или
принудительным sync для Integration: real Redis mode выбирается явно и проходит identity guard.

После identity/connectivity guards install получает UTC timestamp через `clock_timestamp()`
проверенного `core-postgres` и сверяет его с native PHP clock в `app`: PostgreSQL timezone — UTC,
расхождение не больше 60 секунд, текущий UTC месяц совпадает. Этот единственный anchor передаётся
всем migrate/seed subprocesses обоих reset как `PROCESS_CORE_INSTALL_AT` вместе с
`PROCESS_CORE_INSTALL=1`. Mutable и immutable Carbon фиксируются до bootstrap kernel и settings
migrations; MinimalSeeder наследует этот clock. Отсутствующий/невалидный anchor или чужой месяц
отвергаются; перед каждым reset clocks проверяются повторно. Переход месяца останавливает pass
до остановки workers/DROP и требует нового запуска. Произвольная historical дата не используется:
bootstrap partitions, pg_partman и SQL current-month остаются в одной эпохе. Anchor и source
clocks сохраняются в `result.json` и `install-clock.json`, повторные observations — в reset reports.
Полные seed timestamps сохраняются в manifests без удаления значимых полей.

Minimal seed использует существующий `PlatformSeeder` с фиксированными временем/UUID и создаёт
только неактивную обучающую площадку. Предметные migrations и behavior не меняются. Reset очищает
тестовый Redis целиком, два fixture buckets MinIO и local runtime data; reports сохраняются.
Process root работающего HTTP server живёт до его выхода, а workers/scheduler останавливаются
до очистки. Для regression workers остаются остановленными, чтобы не состязаться с RefreshDatabase.

Readonly runtime inputs перечислены с SHA-256 в `tests/Fixtures/ProcessCore/runtime-inputs.json`:
gold corpus, один существующий GSFX binary, audit research и prompt canon. Отсутствие или иной hash
останавливают harness; реальный GSFX scenario обязан исполнить assertions, skip не принимается.
Два исторических source inputs восстановлены из Git commit
`0a071dca52d95e91a534cd335e24dfb036deef76` в `tests/Fixtures/ProcessCore/historical/`; manifest
сохраняет их исходные пути и hashes. В контейнере они доступны только в требуемых тестами путях.
Это inputs, а не новые plan carriers.

`core-test-up` выполняет только `vendor-sync.sh --check` и экспортирует настоящие shallow Git
objects текущего HEAD и pinned dependency из существующего локального checkout. Credential-free
snapshots лежат в ignored `docker/tests/process-core/runtime-inputs/` и подключаются read-only;
host `.git`, Git config, hooks и credentials не копируются. Dependency checkout содержит только
два package subtrees из закрытого lock manifest. Stale/incomplete snapshot отвергается: после
остановки isolated runtime удалите только этот generated каталог и повторите `core-test-up`.
Публичный fixture Redis password соответствует `--requirepass`, doctor выполняет настоящие
diagnostics без подмены результата.

Два prompt roots подключены через отдельные test-owned volumes поверх readonly resources.
Перед suite/quality `prepare-runtime.php` копирует в них настоящий canon из readonly mounts;
PromptApply/PromptRoot могут менять файлы, host canon остаётся read-only. Повторная подготовка
удаляет stale probes.

Исходный inventory до переноса находится в `tests/Fixtures/ProcessCore/manifest.json`:
302 test-файла, 1 826 статически найденных test/it declarations, 81 migration и 23 class/FQCN.
Dataset cases подсчитываются отдельно по actual JUnit; число declarations не равно executed count.
Классы move map не меняются. В checks.2 исправлен единственный устаревший canary исходного
`CoreKnowsNoDocumentTypeTest`: 110 вместо 109 с явным membership assertion для нейтрального
`CooperativeListingSourceMethod`, добавленного до P4 коммитом `60d6162c`. Имена всех трёх
сценариев сохранены; manifest сохраняет `original_sha256`, актуальный hash и причину изменения.
Baseline verifier проверяет актуальные hashes. После механического переноса P4.2 manifest хранит
`original_path`, `original_sha256` и, для классов, `original_fqcn` вместе с текущими значениями и
причиной изменения. Исходные 1 826 declarations сохраняются; новая architecture/factory проверка
добавляется сверх baseline. `tests/Fixtures/ProcessCore/platforms-move-map.json` перечисляет все
переносы, исходные hashes fixture bytes и решения оставить смешанные тесты на месте. Import
extension model в одной migration обновлён без изменения DDL; исходный hash также сохранён.
Evidence P4.2 связывает before install/unit/regression reports P4.1 с теми же input hashes,
сохраняет сценарии каждого затронутого файла и actual JUnit узких after checks. P4.3 сравнивает
полный regression с этими before results; after-run не заменяет исходный результат.

P4.3 сохраняет исходные 2014 executed cases (включая dataset labels) в versioned
`tests/Fixtures/ProcessCore/original-executed-scenarios.json`. Provenance содержит before candidate
HEAD/dirty/input-tree hashes и hashes исходных JUnit/schema/catalog/seed outputs. Проверены все
15 исходных regression artifacts; canonical JSON cases воспроизводит hash исходного
`executed-scenarios.json`. Это before evidence P4.1, а не pass текущего candidate.
Regression сопоставляет каждый file/name через точную move map, требует passed status и пишет
`scenario-comparison.json` до отказа при потере/переименовании/skipped case или дубле.
При owning cutover D18 допускает перенос через versioned
`tests/Fixtures/ProcessCore/scenario-transfers.json`: точный исходный file/name (включая dataset),
все тесты-замены, причина и нормативный контракт. Карта привязана к hash исходного executed
baseline; сам baseline не меняется. Guard требует успешного однократного исполнения **каждой**
замены, отвергает неизвестные/повторные источники, пустые списки, self-reference и цепочки.
Нет исключения для удалённого файла, отсутствующего, failed или skipped теста. Report сохраняет
применённые переносы отдельно от неизменённых сценариев и hash карты текущего candidate.
Дополнительные architecture cases разрешены. `core-test-report` требует этот artifact и сверяет
его hash с результатом regression того же input tree.

Единственный временный dependency baseline остаётся
`tests/Unit/Architecture/platforms-dependency-baseline.php`. `dependency_origin` в move map хранит
исходные 50 пар с owners и commit/hash P4.2; permanent roles остаются отдельно в
`PlatformSourceRules::rolePermissions()`. `PlatformsBaselineOriginTest` разрешает только удаление
исходных пар, запрещает добавление/смену owner/дубли и совпадение с permanent permissions.
Новую forbidden dependency нельзя легализовать одновременной правкой baseline.

Передача P1.1–P1.4: использовать существующие core-* targets и schema/FK/check/index/extension/
partman manifests из `core-test-install`; добавить собственные contracts/schema assertions и
fixtures. `manifest.json` хранит 81 исходную migration с hash, move map — paths/FQCN evidence,
а полный after comparison появится в отчёте regression текущего candidate. Process runtime,
core tables, новые config keys и activation domain suites в P4 не создаются. Проверки V1–V7
и запись completion/commit принадлежат координатору; узкие self-checks executor их не заменяют.

P1.1 добавляет полный inventory в [документ схемы Process](../process-schema.md) с owning source
`tests/Support/ProcessCore/schema-inventory.json`: исходные 75 root tables/939 колонок,
81 migration disposition, будущие carriers, типы/nullability/FK/CHECK/UNIQUE/index, retention,
subject ancestors и отдельную stage activation map. Process migrations пока не создаются.
Снимок исходного каталога имеет P4.3 provenance и сверенные migration/model/config hashes;
он не является P1.1 install pass. После каждого reset существующий `snapshot` дополнительно
передаёт реальные schema/catalog в read-only `verify-schema.php --catalog-stdin` и сохраняет
`schema-inventory.json` рядом с raw outputs. Guard сравнивает все root definitions, включая
точность numeric, nullability и реальные FK/check/index; inherited partitions остаются в полном
install comparison. Неизвестная root table или потерянная колонка отвергается.

`verify-baseline.php` теперь сверяет и весь versioned inventory с полным блоком документа;
наличие файла/заголовков не считается полнотой. `core-quality` исполняет также узкие negative
examples `test_schema_inventory.py`, использующие тот же PHP guard без DB/network I/O.
Самостоятельные узкие команды: `php tests/Support/ProcessCore/verify-schema.php` и
`python3 -B -m unittest discover -s docker/tests/process-core -p test_schema_inventory.py -v`.
После owning schema cutover нужно обновить inventory/provenance и document block, затем
получить два clean install; guard не разрешает удалять legacy таблицу до последнего reader.

P1.6 — первый такой owner: `current_item` = P1.6, 83 migrations (две новые ledger migrations,
две изменённые baseline CREATE), 94 root tables/1181 колонок. Carrier с `create_at` ≤ `current_item`
обязан присутствовать в installed baseline с объявленным порядком колонок, именованными
CONSTRAINT и индексами; более поздние carriers обязаны отсутствовать. Строки 21 затронутой
таблицы получены pg_dump/`catalog.sql` проекцией P1.6 DDL на одноразовом PostgreSQL 17
(provenance `amendments`); полное сравнение с реальным каталогом выполняет `core-test-install`.
Fixture `manifest.json` хранит актуальные hashes изменённых и новых migrations.

Reports хранятся в `storage/app/testing/process-core/reports/<run-id>/`: candidate HEAD, dirty digest,
полный input-tree fingerprint (включая untracked product files), config/fixture hashes, exit,
логи, schema.sql, catalog.json, normalized-catalog.json, seeds.json, JUnit и executed-scenarios.json.
Нормализация schema удаляет только случайные pg_dump restrict keys и version header; normalized
partman catalog исключает только операционное `maintenance_last_run`. Raw catalog и seed rows
сохраняются целиком; retention и другие настройки участвуют в сравнении.
`core-test-report` отвергает отсутствующие и устаревшие результаты. Доказательства P4.1 не объявляют
готовность будущих domain suites. План, acceptance и commit записывает coordinator через native
Planner; executor не запускает plan lifecycle и не редактирует plan carriers.

Для дешёвой проверки изменений стенда: `python3 -m unittest discover -s docker/tests/process-core
-p test_rig.py -v`, отдельный `tests/Unit/Support/TestRigIsolationTest.php`, PHP syntax checks и
`git diff --check`. Эти проверки не заменяют `core-test-install` и full regression.
Для direction/shrink guards доступны отдельные `tests/Unit/Architecture/PlatformsDomainLayoutTest.php`
и `tests/Unit/Architecture/PlatformsBaselineOriginTest.php`; их negative examples не меняют product
source и не требуют Process runtime.

## Contracts P1.2 (pure slice)

В `app/Dto/Procurement/Process` и `app/Enums/Procurement/Process` добавлены immutable
value types, восемь WorkInput variants, ровно пять StageOutcome variants, AuthoritySnapshot,
ExecutionLease и WorkCheckpointEnvelope v1. Control version, handler version, work generation,
input/recipe hashes и request epoch остаются отдельными typed значениями. Subject snapshots
содержат уже загруженные реальные bigint/UUID keys и ancestor links, без запросов к БД.

`ProcessPayloadCodec` и `StagePayloadCodec` в `Services/Procurement/Process` — только pure
serialization seams. JSON находится в VersionedPayload; null payload/version допускаются
только парой. Неизвестные поля, variants, enum values, schema versions и coercion scalar types
отвергаются. Stage codecs подключаются явно; test codec существует только в tests/Support.
Стабильная WorkIdentity v1 не добавляет случайный run id к предметному ключу.

Checkpoint envelope хранит stage progress вместе с provider recovery plan. withProgress сохраняет
тот же directive; withoutProviderRecovery возвращает новый envelope с прежним progress.
Resume_from_receipt допускает generation + pinned ReceiptRef; resume_provider_asset — только
cache/file/lookup + receipt. Known-retry и blocked-unknown могут не иметь receipt, но всегда
содержат exact logical/physical IDs, hashes и request epoch. ReceiptRef означает проверенную
owning resolver metadata: codec не проверяет объект в storage, оплату или содержание HTTP response.
Реальные purpose-specific resolver, linkage/recipe revalidation и атомарное consumption реализуют
следующие owning пункты; эти pure checks не объявляют T15/T22 recovery выполненным.

Handwritten `tests/Support/ProcessCore/contracts-v1.json` связан с fixture manifest через SHA-256;
он содержит canonical generation-resume envelope и отрицательный error variant. Остальные
табличные positive/negative случаи находятся в трёх `tests/Unit/ProcessCore/*Test.php`.
T44 в `ProcessCoreArchTest.php` проверяет source direction, handler branching и strict/readonly
границы с отрицательными source fixtures во временном каталоге. ExecutionContext и Spatie Data
исключены из blanket readonly. Это source guards, а не доказательство DB atomicity/concurrency.

Узкие проверки запускаются по отдельным файлам, например:

```bash
# В уже проверенном isolated app, PROCESS_CORE_TEST=1; --no-tia обязателен.
php vendor/bin/pest --no-tia tests/Unit/ProcessCore/CheckpointCodecTest.php
php vendor/bin/pest --no-tia tests/Unit/Architecture/ProcessCoreArchTest.php
php vendor/bin/phpstan analyse app/Dto/Procurement/Process app/Enums/Procurement/Process app/Services/Procurement/Process
```

Process tables, runtime registry/handler activation, ledger и reset baseline этот срез не добавляет.
V1/V2 и полная приёмка группы остаются у coordinator; узкий pass относится только к contracts.

## Приёмка контрактов P1.4

Связь PHP enums/subject types с целевым DDL проверяется в `tests/Unit/ProcessCore/SchemaContractTest.php`; границы передачи P1.6–P1.12 описаны в [process-schema.md](../process-schema.md). Input/outcome и checkpoint tests остаются pure: они не подтверждают admission, outbox, lease races или provider recovery runtime.

Новые проверки запускаются отдельными test files через уже проверенный isolated rig; `PROCESS_CORE_SCHEMA_RIG=1` направляет Python schema checks в его PHP container. Не заменять ими acceptance: V1–V6 coordinator запускает на итоговом candidate, включая negative destructive guards/egress, два install, quality и full existing no-TIA regression. До появления owning assertions будущий domain target должен возвращать `suite_not_implemented`.

## P2.6: диагностика и восстановление после переключения сети

Все физические запросы проходят общий owner, PostgreSQL permit и последний send gate.
Повторы и redirects принадлежат `OutboundExecutor`. Выбор прокси использует точное членство
в route profile; пустой профиль не разрешает неявный direct fallback. Поля страны и прежние
scopes узла остаются метаданными конфигурации. Разрешённые для конкретного назначения узлы
нужно включать в соответствующий профиль явно. Пароль хранится вне БД, в указанном
`secret_ref`; явное повторное применение сида audit-прокси увеличивает credential version,
включая замену значения за тем же именем секрета.

Сетевая доступность и состояние адаптера имеют независимые факты. HTTP 200 одной вкладки
не подтверждает исправность полного разбора. Canary удерживает одного владельца до проверки
всех вкладок и чистых парсеров. После полного успеха параллельность растёт от одного до двух,
затем до обычного лимита. Частичный результат сохраняет только независимо полные проекции;
он не подтверждает полный успех и не участвует в подсчёте поломок контракта.

Повтор одного проблемного входа ограничивается существующим прогоном или карточкой.
Три разных входа с общей сигнатурой ошибки контракта за пять минут переводят соответствующую
версию адаптера в `held`. Таймер не снимает этот запрет. Ошибка при обычной параллельности
отзывает ранее выданные допуски: поздний результат остаётся фактом, но не возвращает доступ.
Очистка умершего canary сохраняет уже применённые подтверждения; новую историю начинает
только явный audited reset. Значения лимитов задаёт `outbound.adapter_health`.

Полная диагностическая проверка использует штатные URL, TLS, лимиты и permits. Она пишет
факты проверки, сохраняя данные закупки и курсоры обхода. При паузе acquisition остаётся
доступна через controls `system` и `monitoring`:

```bash
php artisan process:diagnose eis --adapter=listing --crawler-id=123
php artisan process:diagnose eis --adapter=detail --procurement-id=456
```

Перед снятием `held` прочитайте текущую строку нужной версии (только чтение):

```sql
SELECT p.code, h.capability, h.adapter_version, h.contract_version,
       h.state, h.state_version, h.health_epoch
FROM prc_adapter_health h JOIN prc_platforms p ON p.id = h.platform_id
WHERE p.code = 'eis';
```

Сброс требует оператора с правом управления, непустую причину и точную `state_version`.
Конфликт версии отклоняет сброс. Состояние меняется на `validating`; исправность подтверждает
последующий полный canary. Для отдельной карточки `--release-input` создаёт новый audited
Process intent для `failed_item`, сохраняя исчерпанный budget прежней работы:

```bash
php artisan process:diagnose eis --adapter=detail --reset-version=7 --actor-id=1 --reason='Исправлен контракт парсера'
php artisan process:diagnose eis --adapter=detail --procurement-id=456 --release-input --actor-id=1 --reason='Проверен сохранённый вход'
```

С P2.18 профиль запускается через `process:run` или versioned действие в таблице профилей;
fresh/archive/delta выделяет `process:schedule-acquisition`. Pause/resume/cancel выполняются
через Process control API с RBAC, expected version и причиной. RecoveryReconciler и outbox
relay восстанавливают work/delivery, сохраняя cursor, controls, hold и абсолютный RetryAt.
`crawler:recover-stale`, старые jobs и их unique locks удалены. CancelRun не отменяет работу,
которая нужна другому run. Legacy incidents readers/table сохраняются до P3.17;
circuits readers/table удалены. См. [acquisition-scheduler.md](acquisition-scheduler.md).

Последний полностью полученный HTTP error сохраняется как ограниченный decoded UTF-8
артефакт CSV с идентичностью физического запроса. Промежуточный 503 перед успешным ответом
не становится отдельным отказом входа. Сетевой отказ следующей попытки не наследует тело
предыдущего ответа. Журнал транспорта содержит только разрешённые числовые метрики,
без URL, заголовков авторизации и исходного текста transport error.

## P3.1 documents suite

`make core-test-documents` is implemented and fails on missing/empty/skipped assertions. It runs
`tests/Feature/ProcessCore/Documents`, the two T12 storage concurrency files, and the retained
`tests/Feature/Procurement/Prepare` / `tests/Feature/Document/DocumentDownloaderTest.php` safety
corpus after a guarded clean reset. It proves this immutable/storage slice; it does not claim the
future feeder, OCR batching, fairness or paid AI stages are activated or accepted.

The historical audit document required by legacy regression is a byte-identical test fixture under
`tests/Support/ProcessCore/RuntimeInputs/`. `runtime-inputs.json` records its original Git blob and
SHA-256 and preserves its runtime path. The research worktree is not restored or mounted.

## P3.3 prepare assertions

`make core-test-documents` дополнительно исполняет T26 manifest/checkpoint и real-tool tests.
Fixture `tests/Fixtures/ProcessCore/Documents/prepare-v1.json` фиксирует original page mapping и
hash mixed PDF. OCRmyPDF/qpdf/Tesseract/Poppler запускаются внутри того же isolated app;
AI не вызывается. Проверяются native+scanned text, sidecar omission, exit0 tesseract timeout,
restart/split/singleton/truncation, stale head и stop/reap after PHP SIGKILL.

PHP converter supervisor использует Linux `/proc`, process groups и subreaper. Смена image/tools
требует нового `documents.prepare.tools_version` и preparation recipe, иначе prepare получает
явный hold. `PrepareWorkspace::cleanup` повторяется только по local owner/stop receipts;
active marker или неподтверждённое удаление сохраняют charge. Внешний reconciler, который
выбирает такие workspaces, подключается при runtime cutover P3.5. Одно истечение lease не
разрешает release storage. Full T10/T11 и feeder acceptance остаются P3.5/P3.6.

## P3.5 document cutover checks

`core-test-concurrency` includes `T11DocumentFeederConcurrencyTest`: two actual PHP processes and
PostgreSQL connections, W10/W20, manual+two platform cohorts, parent/child outstanding counts,
running C and immutable age ordering. The fixture stage is screening; shared parent policy also
limits extraction after its owning schema/handler activation. This subset is not full T16 load.

`core-test-documents` includes the registered reference→download→prepare path through actual
Process delivery, local HTTP and storage, source-disabled preparation, pending real AI boundary,
immutable versions and object repair. The retained format corpus calls a test-only strategy
probe; it does not dispatch retired jobs. D18 mappings bind changed lifecycle scenarios to their
new executed cases. `core-test-regression` continues to enforce every original scenario or its
explicit executed replacement, without TIA, skips or guard exceptions.

If `core-test-up` reports a stale runtime Git snapshot, first run guarded `core-test-down`. Only
after successful shutdown remove `docker/tests/process-core/runtime-inputs/` and rerun up. Never
reuse stale metadata, relax identity guards or touch dev volumes.

The full regression CLI runner has an explicit finite `memory_limit=1G` for the retained Laravel/Filament corpus. Worker/application memory settings are unchanged. This is an isolated test resource allowance, not a production memory/throughput claim.

P3.5 per-case scenario dispositions: [document cutover](document-cutover-p35.md).
