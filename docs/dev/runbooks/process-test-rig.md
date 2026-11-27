---
audience: dev
type: runbook
status: living
---

# Изолированный process-core стенд

Все команды выполняются из repository root. `make core-test-up` собирает отдельные PHP и
образы PostgreSQL 19 с pg_partman и поднимает project `agelto_core_test`. Docker Compose использует
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
содержит публичный fixture env. Runtime storage, Redis, PostgreSQL и Silo принадлежат named volumes
этого project. Все credentials синтетические. Сеть `agelto_core_test_core` имеет `internal: true`,
портов на host нет. HTTP fixtures, ограниченный HTTP proxy и fake Gemini находятся в этой сети.
CONNECT/TLS и полноценный Gemini protocol получают assertions в своих owning пакетах;
текущие fixture endpoints не считаются доказательством этих контрактов.

| Команда | Текущий контракт harness |
| --- | --- |
| `make core-test-up` | Compose identity, connectivity, healthy app/Postgres/Redis/объектное хранилище Silo |
| `make core-test-env` | Positive/negative target checks, fixture manifest/wire assertions, raw socket egress deny и fixture reachability |
| `make core-test-reset` | Guard, остановка только test workers/scheduler, DROP/CREATE `agelto_test`, migrate, minimal seed, Redis/object/runtime cleanup, readiness, manifests, запуск test workers |
| `make core-test-install` | Два полных reset; точное сравнение normalized schema/seed/extension/partman manifests |
| `make core-test-unit` | Fixture manifest/wire assertions и существующий Unit suite без TIA; zero cases, skips и отсутствие assertions не проходят |
| `make core-test-regression` | Clean reset и весь project PHP suite без TIA; actual JUnit scenario inventory |
| `make core-quality` / `make quality` | Pint `--test`, configured PHPStan level max, Architecture suite и baseline/schema/docs checks |
| `make core-manifest-sync` | Локальное обновление SHA-256 неизменяемых контрактных и gold-фикстур |
| `make core-test-report` | Проверка наличия результатов всех текущих gates и совпадения candidate input tree |
| `make core-test-down` | Identity guard, удаление только test project и его volumes |

Предметные suites выполняют guarded clean reset, затем пути из `SUITE_PATHS` в
`docker/tests/process-core/rig.py`. Пустая выборка, skip и отсутствие assertions — ошибка.

| Suite | Что проверяет |
| --- | --- |
| `core-test-execution` | Ledger, controls, admission, claim, lease, checkpoint, finalize и recovery |
| `core-test-concurrency` | Отдельные PHP processes/PG connections, locks и fencing, capacity и fairness |
| `core-test-network` | Catalog, permits, actual-send gate, transport, health reducers и catalog races |
| `core-test-platforms` | Config-only registry, adapters, identity, extension contracts и архитектурные границы |
| `core-test-acquisition` | Listing/detail/status, run lifecycle, coverage и передача документов |
| `core-test-observability` | Проекции, replay, freshness, retention, operator UI и cutover guards |
| `core-test-documents` | Immutable storage, download/prepare, cleanup, real-tool PDF и storage races |
| `core-test-ai` | Provider transport, receipts/recovery, validation, chunking и publication с fake LLM |
| `core-test-e2e` | Полный pipeline в дочерних Horizon workers, HTTP/Livewire UI и platform projections |
| `core-test-faults` | Именованные kills, DB/Redis failures, lost delivery, lease fences и paid-response recovery |
| `core-test-load WIDTH=10` / `WIDTH=20` | Отдельные 1800-second/1000-document profiles, fairness и demand FIFO |

Некорректная WIDTH отвергается до reset. `load.json` и `load-validation.json` сохраняют
измерения и verdict; малый regression corpus не удовлетворяет load gate.
Browser service доступен через профиль `browser`; operator E2E проверяют HTTP responses
и Livewire actions, но не browser automation.

Domain E2E запускает реальные handlers через дочерние Horizon workers до publication/status
refresh, с immutable disks и local TLS. Test-only bootstrap требует явного
`PROCESS_CORE_PIPELINE_FILE` под test storage. `HorizonPipelineTest` потребляет настоящий
`process-ai` и связывает slices с work/attempt/request facts; его bootstrap требует
`PROCESS_CORE_ACCEPTANCE_FILE`. Обычный service Horizon потребляет только
`process-core-fixtures`; scheduler работает с пустым business baseline.
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
Manifest помечает coverage `fixture_wire_only`: предметные контракты проверяют отдельные suites.

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
host `.git`, Git config, hooks и credentials не копируются. Каждый dependency snapshot содержит только
два package subtrees из strict v2 lock manifest (v1 Azguard нормализуется при чтении).
`runtime-inputs.json.repositories` фиксирует полный source SHA и SHA-256 обоих payload
для Azguard и Proxyma. Snapshot проверяется по точным pinned Git blobs; missing/changed/extra
files, credential paths, symlinks, hooks и посторонний Git config отвергаются.
Compose монтирует оба snapshot только read-only, без host checkout/.git/credentials. Stale/incomplete snapshot отвергается: после
остановки isolated runtime удалите только этот generated каталог и повторите `core-test-up`.
Публичный fixture Redis password соответствует `--requirepass`, doctor выполняет настоящие
diagnostics без подмены результата.

Два prompt roots подключены через отдельные test-owned volumes поверх readonly resources.
Перед suite/quality `prepare-runtime.php` копирует в них настоящий canon из readonly mounts;
PromptApply/PromptRoot могут менять файлы, host canon остаётся read-only. Повторная подготовка
удаляет stale probes.

## Baseline и сохранение сценариев

`tests/Fixtures/ProcessCore/manifest.json` хранит историческое происхождение и текущие носители
сценариев. Активные SHA-256 в нём относятся к неизменяемым контрактным данным.
`make core-manifest-sync` обновляет их и хэши `estimates/gold/` в runtime-манифесте.
`original-executed-scenarios.json` сохраняет 2014 исторических
executed cases с dataset labels и provenance исходных reports. Этот baseline не переписывается
при переименовании или замене теста.

Regression сравнивает actual JUnit с baseline и сохраняет `scenario-comparison.json`.
Переносы описывает `scenario-transfers.json`: точный исходный file/name, все тесты-замены,
причина и контракт. Guard требует однократного успешного выполнения каждой замены;
неизвестные/повторные источники, пустые списки, цепочки, missing/failed/skipped cases запрещены.
`core-test-report` проверяет hash карты и regression evidence того же input tree.
Имена файлов, сценариев и групп описывают поведение; исторические идентификаторы остаются
в acceptance/provenance и связываются с текущими сценариями явно.

Временный архитектурный baseline устранён. `PlatformsBaselineOriginTest` и
`PlatformsDomainLayoutTest` требуют его отсутствия: историческая зависимость должна быть
удалена либо точно соответствовать разрешённой постоянной роли в `PlatformSourceRules`.

[Документ схемы Process](../process-schema.md) строится на
`tests/Support/ProcessCore/schema-inventory.json`. После reset `verify-schema.php --catalog-stdin`
сверяет настоящий каталог: root tables, порядок/типы/nullability колонок, FK/CHECK/UNIQUE и
индексы. Inherited partitions дополнительно участвуют в полном install comparison.
После изменения схемы нужно обновить inventory/provenance и документ, затем выполнить
двойной clean install. Guard не разрешает удалять legacy таблицу до последнего reader.

`verify-baseline.php` сверяет hashes и полный inventory block документа. `core-quality`
также исполняет negative examples `test_schema_inventory.py` без DB/network I/O.
Узкие команды: `php tests/Support/ProcessCore/verify-schema.php` и
`python3 -B -m unittest discover -s docker/tests/process-core -p test_schema_inventory.py -v`.

Reports хранятся в `storage/app/testing/process-core/reports/<run-id>/`: candidate HEAD, dirty digest,
полный input-tree fingerprint (включая untracked product files), config/fixture hashes, exit,
логи, schema.sql, catalog.json, normalized-catalog.json, seeds.json, JUnit и executed-scenarios.json.
Нормализация schema удаляет только случайные pg_dump restrict keys и version header; normalized
partman catalog исключает только операционное `maintenance_last_run`. Raw catalog и seed rows
сохраняются целиком; retention и другие настройки участвуют в сравнении.
`core-test-report` отвергает отсутствующие и устаревшие результаты. Acceptance и commit
записывает coordinator через native Planner; executor не запускает plan lifecycle и не редактирует
plan carriers.

Для дешёвой проверки изменений стенда: `python3 -m unittest discover -s docker/tests/process-core
-p test_rig.py -v`, отдельный `tests/Unit/Support/TestRigIsolationTest.php`, PHP syntax checks и
`git diff --check`. Эти проверки не заменяют `core-test-install` и full regression.
Для direction/shrink guards доступны отдельные `tests/Unit/Architecture/PlatformsDomainLayoutTest.php`
и `tests/Unit/Architecture/PlatformsBaselineOriginTest.php`; их negative examples не меняют product
source и не требуют Process runtime.

## Диагностика и восстановление сети

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

Профиль запускается через `process:run` или versioned действие в таблице профилей;
fresh/archive/delta выделяет `process:schedule-acquisition`. Pause/resume/cancel выполняются
через Process control API с RBAC, expected version и причиной. RecoveryReconciler и outbox
relay восстанавливают work/delivery, сохраняя cursor, controls, hold и абсолютный RetryAt.
CancelRun не отменяет работу, которая нужна другому run.
См. [acquisition-scheduler.md](acquisition-scheduler.md).

Последний полностью полученный HTTP error сохраняется как ограниченный decoded UTF-8
артефакт CSV с идентичностью физического запроса. Промежуточный 503 перед успешным ответом
не становится отдельным отказом входа. Сетевой отказ следующей попытки не наследует тело
предыдущего ответа. Журнал транспорта содержит только разрешённые числовые метрики,
без URL, заголовков авторизации и исходного текста transport error.

## Документы и AI

`core-test-documents` включает real-tool PDF cases: OCRmyPDF/qpdf/Tesseract/Poppler,
native+scanned text, sidecar omission, timeout, restart/split/singleton/truncation,
stale head и stop/reap after PHP SIGKILL. Fixture `Documents/prepare-v1.json` фиксирует
original page mapping и hash mixed PDF. Платные модели не вызываются.

Converter supervisor использует Linux `/proc`, process groups и subreaper. Смена image/tools
требует нового `documents.prepare.tools_version` и preparation recipe. Cleanup допускается
только при доказанной остановке writer; active marker или неподтверждённое удаление сохраняют
charge. Само истечение lease не разрешает release storage.

Storage tests проверяют repair/GC в обоих порядках блокировок, quota reservation и неопределённый
результат записи. Prepare проверяет manifest/checkpoint, фактически прочитанные bytes, полное
покрытие страниц и cleanup proof. Document feeder проверяется в отдельных PHP processes на
W10/W20, manual+platform cohorts, parent/child capacity и immutable age ordering.

`core-test-ai` проверяет JSON Schema, DTO roundtrip, exact decimals, source anchors/coverage,
sealed recipes, immutable owner rows, chunk idempotency, provider receipts и recovery, transport
и publication. Fake LLM даёт детерминированный protocol proof; качество реальной модели этим
не измеряется. Полные границы: [quality limits](process-quality-limits.md).

Сохранённый format corpus использует test-only strategy probe; он не активирует форматы,
которые не поддерживает runtime prepare. Исторический audit input — byte-identical fixture из
`tests/Support/ProcessCore/RuntimeInputs/`; его provenance хранит `runtime-inputs.json`.
Research worktree не восстанавливается и не монтируется.

Regression CLI имеет конечный `memory_limit=1G` для Laravel/Filament corpus.
Настройки worker/application памяти этим не меняются.

## Проверка отдельного файла

`make core-test-file TEST_FILE=tests/Feature/ProcessCore/Platforms/PlatformUiRegistryTest.php`
запускает один существующий `*Test.php` из Unit/Feature/Integration через тот же guarded rig.
Directory, CLI options, absent paths и path escape отвергаются. Target не делает reset;
требуется готовая isolated schema. Он удерживает общий project lock, останавливает только
test workers и сохраняет JUnit, список actual scenarios и fingerprint inputs. Для повторного
старта workers используйте обычный guarded runtime lifecycle. Domain/quality acceptance
этим target не заменяется; executor не запускает полную suite из withheld acceptance.

## Бюджет времени прогона

Каждый запуск Pest внутри контейнера `app` ограничен `timeout`: 7200 с для `regression` и
`tia`, 3600 с для `load`, 2700 с для остальных целей. В серии release значение 7200 с применяется к каждому из 13 запусков.
Локальный `make core-test-release` выполняет всю серию последовательно в одном стенде.
В полном CI отдельные изолированные стенды собирают пять последовательных прогонов,
профиль, покрытие, три прогона с фиксированными случайными порядками и три выбранных
последовательных прогона. Внутри каждой группы запуски последовательны; состояние между
группами не переносится. Общая проверка требует все 13 результатов с одинаковым набором
тестов, коммитом, зависимостями и фикстурами и применяет те же пороги покрытия и времени.
`core-test-release-sample` собирает только одну группу для CI и сам по себе не подтверждает
готовность релиза; основной `core-test-release` всегда собирает все группы.
В CI для шардов выделено 1500 с на Pest внутри 45-минутной задачи, для fault — 1800 с, для load — 2700 с. Значение для одного запуска можно
задать через `PROCESS_CORE_SUITE_TIMEOUT=<секунды>`. Без этого ограничения тест, который
ждёт блокировку бесконечно, держал Pest и его сессии PostgreSQL даже после того, как
команда на хосте завершилась.

За 15 секунд до конца бюджета стенд сохраняет в отчёт `blocked-sessions.txt` с активными
сессиями PostgreSQL: состояние, событие ожидания, длительность транзакции и начало запроса.
Пара «idle in transaction» плюс «Lock/relation» указывает на тест, который удерживает
транзакцию и одновременно открыл второе соединение.

## Сбор итоговых результатов

После того как координатор запустил или проверил затронутые проверки приёмки, выполните из
корня репозитория:

```bash
make core-test-report
```

Команда читает существующие отчёты и пишет `reports/<run-id>/summary.json` в тестовый корень.
Она не выполняет reset, доставку в очередь, вызовы провайдера и нагрузочные тесты. Ненулевой
результат сохраняет манифест с ID отсутствующих или устаревших gates и сценариев. Исправьте
доказательства или владеющую реализацию; не переименовывайте старый результат, не выбрасывайте
случай и не превращайте платный или живой пробел в pass. Последний результат авторитетен отдельно
для каждого WIDTH нагрузки.

[Контракт приёмки](../process-core-acceptance.md) описывает точные проверки повторного
использования и трассировку T01–T48. Документация и перечисленные входы, влияющие только на
отчёты, могут отличаться — сборщик их фиксирует. Реальные изменения runtime, конфигурации,
fixtures и тестов требуют нового доказательства для затронутого. Итоговая идентичность кандидата
включает и Git HEAD, и хэш рабочих входов. После коммита координатор собирает отчёт повторно, чтобы
привязать пакет к получившемуся коммиту. Храните исходные отчёты и `inputs.json` вместе с
манифестом: хэши, на которые они ссылаются, делают передачу проверяемой.

У проверки отчётов есть детерминированные негативные примеры в
`docker/tests/process-core/test_acceptance_report.py`; при изменении сборщика запустите этот
Python-файл тестов. Обёртку стенда покрывает `test_rig.py`. Ни один из них не является реальным
результатом нагрузки или восстановления. [Ограничения качества](process-quality-limits.md)
остаются явными, даже когда все детерминированные gates надёжности зелёные.
