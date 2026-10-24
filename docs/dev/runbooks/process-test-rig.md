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
завершившийся `core-test-up`; acceptance V1–V6 выполняются последовательно в listed order.
`flock` исключает одновременные mutations, но не задаёт порядок зависимых команд; pure Unit suite проверяет identity без требования domain migrations.
Для сборки нужны Docker и исходные установленные `vendor/`; зависимости Composer не добавляются.

Исходники подключаются явно и read-only. Host `.env`, `.env.docker`, `local/`, host storage и dev
volumes не подключаются; versioned env examples доступны только как исходники, а runtime `.env`
содержит публичный fixture env. Runtime storage, Redis, PostgreSQL и MinIO принадлежат named volumes
этого project. Все credentials синтетические. Сеть `agelto_core_test_core` имеет `internal: true`,
портов на host нет. HTTP fixtures, ограниченный HTTP proxy и fake Gemini находятся в этой сети.
CONNECT/TLS и полноценный Gemini protocol получают assertions в своих owning пакетах;
текущие fixture endpoints не считаются доказательством этих контрактов.

| Команда | Текущий результат P4.1 |
| --- | --- |
| `make core-test-up` | Compose identity, connectivity, healthy app/Postgres/Redis/MinIO |
| `make core-test-env` | Positive/negative target checks и raw socket egress deny + fixture reachability |
| `make core-test-reset` | Guard, остановка только test workers/scheduler, DROP/CREATE `agelto_test`, migrate, minimal seed, Redis/object/runtime cleanup, readiness, manifests, запуск test workers |
| `make core-test-install` | Два полных reset; точное сравнение normalized schema/seed/extension/partman manifests |
| `make core-test-unit` | Существующий Unit suite без TIA; zero cases и skips не проходят |
| `make core-test-regression` | Clean reset и весь project PHP suite без TIA; actual JUnit scenario inventory |
| `make core-quality` / `make quality` | Pint `--test`, configured PHPStan level max, Architecture suite и baseline/schema/docs checks |
| `make core-test-report` | Проверка наличия результатов всех текущих gates и совпадения candidate input tree |
| `make core-test-down` | Identity guard, удаление только test project и его volumes |

`core-test-execution`, `core-test-observability`, `core-test-network`, `core-test-platforms`,
`core-test-acquisition`, `core-test-documents`, `core-test-ai`, `core-test-concurrency`,
`core-test-e2e`, `core-test-faults`, `core-test-load WIDTH=10` и `WIDTH=20` пока завершаются
с exit 78 и JSON `suite_not_implemented`. Их реализуют owning P1/P2/P3 packages. Это не green skip.
Browser service отдельный, доступен через профиль `browser`; operator E2E ещё не реализован.
Horizon потребляет только `process-core-fixtures`, scheduler работает с пустым business baseline.
Нет live crawling, paid model eval, production deployment или уведомлений.

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
остаётся дополнительным барьером к Docker network isolation. PHPUnit `force=true` сам по себе
не является доказательством identity; resolved config проверяется при boot и перед reset.

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
Baseline verifier проверяет актуальные hashes, остальные исходные Test.php остаются неизменны.
P4.2/P4.3 должны явно адаптировать проверку путей к move map, сохраняя соответствие сценариев.

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
