---
audience: dev
type: reference
status: living
---

# Аудит репозитория — 5 ноября 2026

Исходный HEAD: `0f0322dc2315979e5df4a41979e569992242582d`. Аудит охватывает все основные
области проекта, но не означает построчную проверку каждого файла или доказательство
production readiness. Четыре исполнителя проверили разные области, отдельный ревьюер
проверил внесённые изменения; координатор сверил доказательства и выполнил проверки.
Gemini/agy был запущен на независимую проверку HTTP/production, но завершился по timeout
10 минут без содержательного ответа; его запуск не считается выполненным аудитом.

## Что исправлено

| Проблема | Изменение | Проверка поведения |
|---|---|---|
| Cancel running provider send удалял pinned recovery plan; после падения worker durable receipt и резерв могли остаться без владельца recovery | `WorkMutationRepository::cancelWork()` сохраняет любой `ProviderRecoveryPlan` до settlement | `FinancialRecoveryTest`: receipt до/после отмены, перезапуск recovery, точное списание, отсутствие повторного send и business publication; cleanup после synchronous capture |
| `AnalysisFeeder` брал version lock перед document lock, нарушая общий порядок | Порядок document → version | Существующий `AnalysisPlanningConcurrencyTest` проверяет реальное ожидание document lock в PostgreSQL и единственный analysis |
| Ручной rerun брал analysis/version locks до document; closure не сохранял реального оператора | Document → version → analysis с повторной проверкой ancestry; closure получает actor ID | Existing rerun cases проверяют actor, а concurrency cases — ожидание document lock и same/different-command поведение |
| Первые 25 незавершённых blob repairs могли навсегда вытеснить хвост | UUID cursor продвигается также при false/ошибке и возвращается к началу | 26 реальных reservations: поздний repair восстанавливается на втором тике, ранний — после wrap; неизвестные bytes остаются charged |
| Idle poll feeder очищал degraded heartbeat при постоянном quarantined wake | Idle path также проверяет quarantine | `DaemonReadinessTest`: три idle polls сохраняют degraded без повторного claim; разрешение quarantine возвращает clean |
| 23 существующих negative cases для load/acceptance validator не входили в стандартные gates | `core-test-env` запускает `test_load_report.py` и `test_acceptance_report.py` | Отдельные логи и отказ gate при ошибке; новых дублирующих тестов нет |
| Локальные skills называли удалённые таблицы/классы, старые команды тестов и управления контекстом | Исправлены cheatsheet и семь локальных skills: project-map, llm-layer-dev, estimate-extraction-dev, pest-testing, testing-rules, node-yarn-preflight, ai-context-workflow | Имена сверены с кодом; managed bundles и их manifests не редактировались |
| Living AI-context docs направляли к retired providers/config/lifecycle | Синхронизированы `ai-context/index.md` и `scenarios.md` с canon: Native Planner, local/managed ownership, isolated rig | Сохранены frontmatter и навигация; новые правила не вводились |
| Ошибка producer или parent lane останавливала весь feeder tick | Сбои изолированы по producer/lane; здоровые admissions продолжаются, ошибка сохраняется, wake ждёт чистого tick | `DaemonReadinessTest`: 17 cases, включая три новых; прежние 14 scenarios сохранены по digest |
| Review и gold либо correction сохранялись раздельно | `ReviewAnalysisAction` объединяет review с gold/correction под version/result locks; dataset writer сериализует версии и переиспользует analysis snapshot | 4 concurrency/fault cases, 4 recorder cases и 8 UI cases |
| Horizon master удерживал business graphs неисполняемых CLI commands | Четыре измеренных command dependency graphs перенесены из constructor в native `handle()` DI | Real-worker E2E проходит при прежних 64 MiB, без probe и изменения assertions; 25 CLI cases |
| VitePress не собирал schema document из-за HTML-подобного `provider:<key>` | Литерал заключён в inline code вне generated inventory | Build и проверка 354 файлов distribution прошли |
| Чистая сборка PHP-образа падала на `minio/mc:RELEASE.2025-04-16T18-13-26Z` (A2) | Stage `mc` в `docker/php/Dockerfile` переведён на `minio/minio:RELEASE.2025-04-22T22-12-26Z` с тем же `/usr/bin/mc` | Чистый `make core-test-up` со сборкой образа: PASS (`20261106T051255.180081Z-1212698`) |
| Конкурентное создание организаций только по ОГРН допускало дубли (A6) | В `OrganizationStoreRepository::lockOgrnIdentity()` добавлен `pg_advisory_xact_lock(hashtext('org_ogrn'), hashtext($ogrn))`, а в `OrganizationResolver` — повторное чтение внутри транзакции перед `create()` | `OrganizationResolverTest`: PASS (`20261106T060013.516456Z-1699520`) |
| Backup не включал локальные immutable LLM receipts, имел расхождение ключей watchdog и не чистил verify DB по trap (A8) | `backup-objects.php` и `backup-local-enabled.sh` экспортируют/восстанавливают `llm/receipts/...` с диска `local` с проверкой SHA-256; синхронизированы ключи `backup-watchdog.sh` и `trap cleanup_verify_db EXIT` в `backup-and-verify.sh` | `docker/tests/ingress-backup-contract.sh`: PASS |
| Запросы `/admin/documentation` обходили PHP-FPM в Nginx при совпадении с `/documentation`, а маршруты документации не имели `AuthenticateSession` | В `docker/nginx/default.conf` добавлен FastCGI fallback для `/admin/documentation` и `/admin/documentation/...`; в `routes/web.php` добавлен `AuthenticateSession::class` | `docker/tests/private-documentation-response-contract.sh` и `PrivateDocumentationAccessTest` (`20261106T055932.437952Z-1689954`): PASS |
| Cross-contour ссылки в `AnalysisInfolist`, `EstimateInfolist`, `EstimatesTable` и `Analysis::scopePublicPlatform()`, а также static-кэши `ProcessRunsTable` / `ProcurementInfolist` без `updated_at` | Добавлены проверки `is_public` на связанные закупки/версии документов и ключи инвалидации static-кэша по `updated_at`/счётчикам | `ContourTest` (`20261106T060005.780294Z-1697216`), `AnalysisResourceTest` (`20261106T060737.408531Z-1741411`), `EstimateResourceTest` (`20261106T060742.318046Z-1743331`): PASS |
| Обновление до AzGuard 2.0 (`ebd613cb99b1d72b7b303032e0d7ed0dd7774f4f`) и закрытие RBAC-прав `Dashboard`, `FinalPromptPage`, `LlmSpendChartWidget` | Обновлены `local/vendors/azguard`, `private-vendors.lock.json`, `config/azguard*.php`, `AdminGuardPanelProvider`, 24 enum прав, 4 политики, 3 роли, `User` (`morphMap`), `TestCase` (`InteractsWithAzGuard`), `schema-inventory.json` и `process-schema.md` | `AdminPanelAccessTest` (`20261106T055620.490020Z-1618827`), `AdminRouteInventoryTest` (`20261106T055920.594613Z-1684091`), `OperatorNavigationE2eTest` (`20261106T060653.529470Z-1735870`), `make core-quality` (`20261106T061430.523327Z-1785380`), `make core-test-unit` (`20261106T061112.005839Z-1765014`): PASS |

Историческое имя concurrency-сценария сохранено: оно участвует в `scenario-transfers.json`.
SHA-256 изменённого теста синхронизирован в fixture manifest. Изменённые production-файлы
не входят в исторический список `source_classes`; новых фиктивных pins не добавлено.
Обновлена также owning chain `Llm/acceptance-v1.json` → основной manifest и case inventory
`acceptance-report-v1.json`: FinancialRecovery 23 → 25. Digest прежних 23 scenarios совпал
с историческим; original hashes и capture provenance не переписаны.
После третьей волны обновлены current pins четырёх AI source files во вложенном Llm manifest
и `RepresentationContractTest` в основном manifest; исторические test names не менялись.
После четвёртой волны синхронизированы owning nested report и main pins для daemon
inventory: из текущих 17 cases вычтены три новых, digest оставшихся 14 совпал с прежним.
Один только рост счётчика не использовался как доказательство сохранности scenarios.

## Находки и текущий статус

Открыты A1, A3 и A7 (внешние managed templates и миграция CI workflow). A2, A4, A5, A6, A8, A9 и A10 исправлены и проверены
направленными сценариями, полным regression (**3 099 tests, 23 785 assertions,
0 failures/errors/skipped**, `20261105T190722.559521Z-1058905`) и верификацией пятой волны (`make core-test-up`, `make core-test-reset`, `make core-test-env`, `make core-quality` `20261106T061430.523327Z-1785380`, `make core-test-unit` `20261106T061112.005839Z-1765014`). Остаточный structural
backlog указан отдельно ниже.

### A1. Зависимости с опубликованными security advisories — высокий приоритет

`composer audit --locked` вернул 19 advisories для 9 пакетов; `pnpm audit` — 23 advisories
(по классификации npm: 1 critical, 13 high, 8 moderate, 1 low). Это совпадения версий из lock,
не число доказанных способов атаковать Agelto. Abandoned Composer packages не обнаружены.

Примеры с первичными источниками:

- `symfony/http-foundation 8.1.4`: обработка Cache-Control затронута ReDoS при создании HTTP Request;
  исправление и последующая коррекция описаны в [Symfony advisory](https://symfony.com/cve-2026-108082).
- `phpoffice/phpspreadsheet 5.9.0`: XML scanner допускает обход проверки и XXE/DoS;
  [advisory владельца](https://github.com/PHPOffice/PhpSpreadsheet/security/advisories/GHSA-3x94-m842-937c).
  Другие advisory этой версии касаются ODS exhaustion и redirects внешних изображений.
- `shell-quote 1.9.0` приходит через `concurrently`, то есть относится к инструментам разработки;
  [advisory владельца](https://github.com/ljharb/shell-quote/security/advisories/GHSA-pqg4-j6r4-53mv)
  оценивает severity как High, тогда как npm audit сообщает Critical. Эксплуатация в Agelto не доказана.

Также затронуты Livewire, другие компоненты Symfony, Axios, Vite, follow-redirects,
source-map-js и Vue server renderer. `package.json` принудительно фиксирует Vite 7.3.6;
одного обновления lock без пересмотра override недостаточно. Следующее изменение:
точечные security updates с повтором dependency audit, quality, regression и build.
Зависимости в этой проверке не обновлялись. Объём: отдельное изменение зависимостей,
поскольку обновление общей HTTP/UI/parser основы требует собственной приёмки.

### A2. Чистая сборка тестового PHP-образа — исправлено

Прежний `make core-test-up` останавливался на `docker/php/Dockerfile:3`:
`minio/mc:RELEASE.2025-04-16T18-13-26Z` (`pull access denied / insufficient_scope`).
Stage `mc` переведён на уже используемый в проекте официальный образ
`minio/minio:RELEASE.2025-04-22T22-12-26Z`, содержащий бинарник `/usr/bin/mc`.
Чистый `make core-test-up` со сборкой образов прошёл успешно (`20261106T051255.180081Z-1212698`),
последующие `make core-test-reset` (`20261106T052756.393530Z-1297290`), `make core-test-env`
(`20261106T052816.239593Z-1310995`) и `make core-test-install` (`20261106T052818.174967Z-1312171`) также завершены с `exit: 0`.

### A3. CI и текущий изолированный test rig расходятся

`.github/workflows/ci.yml:55–62` запускает dev bootstrap, затем `make quality`, который теперь
требует уже работающий `agelto_core_test`. Bootstrap создаёт другой Compose project.
Jobs tests/coverage/release-quality и `tia-baseline.yml` используют legacy dev carriers,
хотя `PermitFixtures` и `WireFixture` требуют core rig. На чистом runner это не может
подтвердить полный текущий suite. Локальный заранее запущенный core rig скрывает проблему.

Исправление требует согласованной миграции carriers, а не установки `PROCESS_CORE_TEST=1`
в dev-контейнере. Сохранить no-TIA, no-skip, scenario inventory, JUnit, coverage floors и
reports при ошибке. Отдельно снабдить CI обязательным GSFX binary: один runtime input
находится вне Git и должен поступать как immutable artifact с проверкой hash.

Порядок работ: восстановить image build → подготовить locked dependencies/fixtures →
перевести quality/regression на core rig → добавить guarded coverage/profile/random-order
режимы → перенести release evidence и artifact paths → синхронизировать shell contracts/docs.
Текущий core regression не измеряет Clover coverage; floor 88.22%/55% не подтверждён этим аудитом.
Объём: отдельное изменение CI/test harness с приёмкой на чистом runner.

### A4. Отказ producer блокировал все feeder lanes — исправлено

`FeederDaemon::tick()` теперь отдельно перехватывает и логирует ошибки каждого producer
и parent lane, продолжая остальные в прежнем порядке. При ошибке tick остаётся failed/degraded;
claimed wakes не получают acknowledgement,
а следующий чистый tick может их consume. Прежний releaseClaim path сохранён: при его сбое
исключение release может заменить исходное, а unpublished wakes ждут release/expiry.
Частичный прогресс не маскируется как успех.

Два новых A4 cases проверяют healthy producer/listing после ошибки, независимую lane
и сохранение wake до чистого tick. Третий новый daemon case относится к прежнему quarantine fix.
`DaemonReadinessTest`: 17 PASS; прежние 14 cases сохранены по inventory digest. Изоляция
действует между producers и parent lanes; poison candidate внутри одного producer не исправлен.
Общая fairness/admission policy не менялась.

### A5. Ранние незавершённые blob repairs вытесняли последующие — исправлено

Раньше выборка всегда начиналась с `ORDER BY b.id LIMIT 25`; partial/unproven writes
оставались в `writing`. Добавлен UUID cursor с wraparound и продвижением до обработки
каждого кандидата, включая false/исключение. SQL и hydration кандидатов принадлежат
`ArtifactRepository`; reconciler отвечает за обход и recovery. Непроверенные writes
не освобождаются. Один направленный regression с 26 стабильными ID проверяет проход
до хвоста, изоляцию ошибки первого объекта и возвращение к ранним repairs.
`core-test-documents`: 236 tests PASS после изменения.

### A6. Конкурентное создание организаций только по ОГРН — исправлено

`OrganizationResolver.php` ранее искал OGRN-only организацию до транзакции и создавал новую запись
при `null`, не сериализуя конкурентные вставки без ИНН. В `OrganizationStoreRepository::lockOgrnIdentity()`
добавлена транзакционная блокировка `pg_advisory_xact_lock(hashtext('org_ogrn'), hashtext($ogrn))`,
а в `OrganizationResolver` внутри транзакции выполняется повторная проверка `findByOgrn()` перед
созданием организации и привязкой внешних идентификаторов. Поведение проверено в
`OrganizationResolverTest` (`20261106T060013.516456Z-1699520`).

### A7. Часть инструкций остаётся устаревшей у своих владельцев

- Generated `.claude/rules/tests.md:17` направляет тесты в dev app. Владелец указан внутри:
  harness agent-packs / backend-laravel template. Исправлять template и обновлять pack;
  локальная generated-копия не изменялась. Приоритетный project AGENTS уже требует core rig.
- `.claude/workflows/estai-*.js` остаются discoverable и привязаны к отсутствующему EST-AI
  carrier; архивный lifecycle не запускался. Runtime callers в tracked проекте не найдены,
  но discovery идёт через workflow metadata: retirement нужно оформить явно.
  Противоречия в living `docs/dev/ai-context/index.md`, `scenarios.md` и local skill устранены.
- `docs/dev/process/testing.md` и `docker/tests/quality-contract.sh` обещают старый состав
  `make quality` и dev-команды. Исправить вместе с A3; assertions не удалять ради зелёного CI.
- В living workflow glossary исправлен `CrawlerRun` → `CrawlRun`, в database guide —
  `org_procurement_parties` → `prc_procurement_parties`. Исторические описания удалённых
  механизмов не переписаны; массового переименования сущностей/framework методов нет.

### A8. Backup локальных immutable LLM receipts — исправлено

В `docker/host/backup/backup-objects.php` добавлены `--include-local-receipts` при экспорте и
восстановление `disk=local` для префикса `llm/receipts/` с обязательной проверкой размера и SHA-256.
В `docker/host/backup/backup-local-enabled.sh` экспорт и restore staging дополнены передачей
локального пути `storage/app/private`, а также синхронизированы канонические поля статуса
(`finished_at_epoch`, `status`, `summary`, `manifest_sha256`), проверяемые `docker/scripts/backup-watchdog.sh`.
В `docker/postgres/backup-and-verify.sh` установлен `trap cleanup_verify_db EXIT` для гарантированного
удаления временной БД проверки при любом сбое. Контракт подтверждён прогоном `docker/tests/ingress-backup-contract.sh` (`PASS`).

### A9. Неатомарные review и gold/correction — исправлено

`ViewAnalysis` делегирует `ReviewAnalysisAction`: transaction блокирует document version,
затем перечитывает/блокирует analysis. Approval audit и gold, либо correction и rejection,
commit/rollback происходят вместе. `DatasetExampleStoreRepository` под тем же version lock
выделяет следующую gold version и переиспользует snapshot по analysis identity; конфликтующие
исторические snapshots отвергаются. Уже Approved сохраняет исходный reviewer audit;
историческое approval без snapshot может быть дополнено повтором. Recorder сохраняет прежние
no-op для screening и extraction без estimates. Устаревший docblock исправлен.

UI mutations имеют backend `update` authorization. Существующий `AnalysisReviewRepository` ограничивает update
review-полями; это локальная граница action, не гарантия неизменяемости всех моделей проекта.
Четыре PostgreSQL concurrency/fault cases подтверждают same-analysis dedup, версии для двух
анализов, rollback approval при ошибке gold и rollback correction при ошибке review.
Recorder: 4 PASS, UI: 8 PASS. Новые отдельные отправки rejection по-прежнему создают отдельные
коррекции; opposite approve/reject ABA и replay-idempotency rejection этой правкой не заявлены.
Importer upsert policy для dataset/gold сохранена: explicit import может обновить gold,
связанный с Approved analysis. Атомарность approval не означает универсальную неизменяемость gold.

Меньший риск в Reference: `ClassifierImporter` принимает пустой code и превращает
нечисловой level в 0. Это malformed operator input, не дефект обычного справочника.
При доработке импорта отклонять такие строки с номером до writes; не менять явно принятую
политику skip отсутствующего input. Runtime reproduction этой находки не выполнялся.

### A10. Horizon master memory recycler прерывал real-worker E2E — исправлено

Baseline real-worker E2E остановился на `Memory limit exceeded: Using 65/64MB`:
`MonitorMasterSupervisorMemory` сравнивает `memory_get_usage()` с прежними 64 MiB и вызывает
`terminate(12)`. Это не PHP OOM и не доказанная утечка worker. Проба удаления eager
`StageRegistry` не помогла и была отменена.

Ограниченный bootstrap probe показал удерживаемые constructor graphs неисполняемых CLI
commands при Artisan registration. В четырёх измеренных владельцах (`GoldBuildCommand`,
`PromptsSuggestCommand`, `VerifyDocumentUid`, `DatasetExportCommand`) business dependencies
перенесены в native `handle()` DI; private helpers получают их явно. CLI metadata,
validation/authorization, outputs, network gates, vendor и memory limits не менялись.

Paired probe: baseline loop1 63.723 MiB → recycler при 64.2495 MiB; после исправления
loop1 62.876 MiB, loop10 63.400 MiB, E2E PASS. Probe имеет собственный overhead; inclusive
constructor deltas не складываются в точную экономию памяти. Вся instrumentation удалена;
повтор исходного E2E без probe: 3 PASS, соответствующие CLI: 25 PASS. Этот результат
подтверждает bounded fix для проверенного pipeline, не измеряет production memory budget.

## Код, структура и необходимость тестов

### Структурная проблема шире размера файлов

Подтверждено повторяющееся смешение orchestration, SQL, lock protocol, JSON codecs и
container assembly. Одни и те же правила распределены между Services, Repositories и UI;
агенту приходится восстанавливать их по нескольким формам данных. Это самостоятельный
приоритет уборки, даже когда поведенческие тесты проходят.

`AnalysisFeeder` уже переработан в этой волне. В нём остались выбор профиля, построение
snapshot и атомарная orchestration; SQL/aliases убраны. `PreparedAnalysisCandidate` даёт
типизированный вход; `AnalysisRepository` владеет backlog и Document→Version lock/revalidation;
`IntentRepository` — retirement pending; `RunStoreRepository` — closure созданного run с
transition. Новых repositories/interfaces не добавлено. Файл стал длиннее из-за явных
аргументов, но основной `reconcile()` теперь показывает сценарий целиком. Это упрощение
понимания и владения правилами, а не гонка за числом строк.

Следующие 11 мест проверены по исходникам. Порядок отражает риск ошибочного изменения;
это не список автоматически доказанных runtime-багов.

Вторая волна уже принята: `RerunAnalysisAction` использует typed `ReanalysisSource` и
locked eligibility у `AnalysisRepository`; `DocumentFeeder` показывает три bounded
прохода, их выборки и revalidation mutation принадлежат `DocumentWorkRepository`;
`DocumentStorageReconciler` получает typed batches от владельцев хранения. Существующие
SQL predicates и transaction boundaries сохранены, кроме явно описанных исправлений
lock order, actor и starvation. Новых repositories/interfaces не появилось.

Третья волна прошла независимый source review и поведенческие проверки:

- `StoredDocumentWriter` сохраняет object verification и orchestration; adoption, catalog,
  accounting и imported representation SQL находятся у существующих владельцев хранения.
  Reservation-free import сохраняет high watermark, historical-version eligibility и
  conservative incomplete coverage. `FinalizeRepresentationAction` вызывает writer напрямую;
  forwarding write API удалён из `RepresentationStoreRepository`, read API сохранён.
- Normal/diagnostic acquisition используют один exact catalog lookup и одну request-local
  сборку source в клонированном container. Различие DetailPartsSource/DetailSource и guards
  до effects сохранены. Новых factory/interface классов нет.
- Один `AnalysisRequestLink` заменяет знание SQL aliases у семи consumers. Exact physical call,
  Process input hash и provider semantic hash остаются разными проверками. `ChunkCommitter`
  сохраняет порядок settlement → chunk → partition → consume → window; window mutations
  принадлежат `AnalysisRepository`, accepted inventory — `ChunkRepository`, partition policy —
  `CoverageValidator`. Плотные вызовы в `AnalysisGeneration` разобраны на именованные аргументы.

Четвёртая волна принята независимым review и направленными проверками: review/gold/correction
получили owning transaction и persistence; feeder локализует producer/lane failure без потери
wake; четыре CLI commands откладывают business DI до исполнения. Readability и backend
mutation guards изменены в этих границах, без общей смены state-machine или importer policy.
Для будущих CLI memory проблем полезно сначала измерять registration graph, затем откладывать
только доказанно ненужные до исполнения dependencies; это наблюдение, не новое project rule.

Оставшиеся части этих файлов указаны отдельно: archive-child persistence, transport assembly
и publisher coverage codec не выдаются за выполненный refactor.

| Приоритет / место | Что смешано | Следующее ограниченное изменение |
|---|---|---|
| 1. `ViewAnalysis` + `GoldExampleRecorder` | UI, review transition, version allocation и запись gold | Выполнено в четвёртой волне: ReviewAnalysisAction, locked review owner и existing dataset writer; UI показывает outcome. A9 закрыт в описанных границах |
| 2. `StoredDocumentWriter` | Входной array, object I/O, CAS/capacity, versions и representation SQL; repo→service→repo forwarding | Adoption/accounting/imported representation выполнены в третьей волне. Archive-child persistence и `DocumentStoreRepository::createVersion` forwarding остаются отдельным шагом |
| 3. `RerunAnalysisAction` | Eligibility/unknown guards, блокировки, snapshot, intent/work/run scaffolding и audit | Выполнено во второй волне; сохранены command dedup и identity guards, исправлены lock order и actor |
| 4. `AcquisitionHandler` + `AcquisitionDiagnostic` | Дубли catalog/contract lookup и per-request container composition | Выполнено в третьей волне; exact lookup у SourceCatalogRepository, local composition у AcquisitionSources |
| 5. `AnalysisGeneration` | Recovery mapping, representation/JSON, request assembly, route и ledger outcome | Typed linkage и readability выполнены в третьей волне. Transport composition остаётся отдельной ответственностью для следующего изменения |
| 6. `WorkStoreRepository::storeIntent/storeWork` и callers | 10–11 аргументов, рядом hashes, поколения и IDs одного scalar type | Именованные аргументы в callers; typed command только при реальном повторении группы данных. CAS SQL остаётся внутри repository |
| 7. `ChunkCommitter` | Settlement, chunk, coverage, mapping decode/consume и window SQL | Выполнено в третьей волне; exact linkage/consume и window progress у существующих owners, единая caller transaction |
| 8. `CoverageValidator` + `EstimatePublisher` | Policy, queries, provenance decode и дубли coverage JSON semantics | Accepted ranges и partition разделены в третьей волне. Publisher representation codec/digest остаётся отдельным шагом |
| 9. `DocumentFeeder` + `DocumentRevalidator` | Три scans знают private intent/work tables и JSON keys соседних владельцев | Выполнено во второй волне; candidates и locked allocation у DocumentWorkRepository |
| 10. `DocumentStorageReconciler` | Candidate SQL, row hydration, eligibility и GC/recovery orchestration | Выполнено во второй волне; typed batches у StorageCapacity/Artifact, исправлен A5 |
| 11. `EvalRunComparator.php:209–249` | Verdict policy, DB reads и второе чтение/декодирование splits | Results у EvalRunReadRepository; единый strict splits contract у existing split owner. Текущий planner менее строг, заменить им comparator без изменения контракта нельзя |

Следующий практический scope: оставшиеся callers с плотными аргументами (6), затем отдельно
archive-child persistence/forwarding (2), transport composition (5), publisher codec (8) и
strict eval splits/read ownership (11). Выполненные части строк не требуют повторной миграции.
Не объединять переезд кода со сменой SQL predicates, схемы, wire JSON, digests и thresholds.
У каждого изменения сохранять поведенческие owning tests; не добавлять зеркалящие private
методы unit-тесты. Отдельный общий WorkflowService или интерфейс на каждый класс не нужен.

### Инвентарь и тесты

Исходно 2 638 tracked файлов, около 17.5 MB; 1 417 PHP-файлов в `app`.
Точных дубликатов tracked содержимого свыше 100 bytes не найдено. `vendor`, `node_modules`,
runtime reports и исходные пользовательские файлы ignored; их объём не является мусором Git.
Крупные schema/fixture/provenance JSON — действующие входы guards, удалять их нельзя.
ИИ-агентам нужны выборки по ключу/символу и локальные skills, а не загрузка этих файлов целиком.
Проверка синтаксиса всех tracked Python (19) и shell (74) файлов прошла. Три workflow JS
с metadata export и top-level return рассчитаны на собственный runner; обычный Node
syntax checker не принимает этот формат, поэтому его отказ не записан как дефект workflow.

Размерные hotspots: `RecoveryRepository` (861 строк), `EvalMetrics` (754),
`WorkStoreRepository` (703), `EvalRunComparator` (623), GSFX mapper/reader (около 560).
Размер сам по себе не обосновывает дробление; сначала выделить конкретную ответственность
и измерить влияние на изменение/проверку. Плоскую архитектуру пяти доменов сохранять.
`resources/views/welcome.blade.php` — используемый Laravel starter, а не dead file;
заменять после выбора продуктового входа. Массовые переименования не предложены.

В default PHP suites: 70 Unit, 371 Feature, 22 Integration test-файла; Playwright —
один auth setup и три spec. Важны реальные concurrency, fencing, receipt/crash,
storage/tool-processing, Horizon и authorization сценарии. Повтор одного сценария в
domain gate и full regression — диагностический overlap, не лишняя копия теста.

Кандидаты на замену: source-text UI проверки `FilamentSchemaContractTest` и
`FilamentTableContractTest`. Поиск `Section::make`/hidden-toggle по исходнику может пройти
из-за комментария или другой колонки. Проверять построенные schema/column objects;
сохранить source guards там, где контракт действительно относится к dependency/структуре.
Пустых application tests не установлено. `PaoParatestContractTest::assertTrue(true)` —
отдельный runner smoke вне default suite, не заявленное бизнес-покрытие.

## Покрытие аудита и пределы доказательств

| Область | Что проверено | Предел |
|---|---|---|
| Process | claim/lease/checkpoint/finalize, cancellation, admission/fairness, outbox и четыре daemon | Это выборочное source review критических путей, не exhaustive interleaving proof |
| Network/LLM | DNS pinning, TLS, proxy, permit/health SQL, send gate, ledger, receipts, unknown recovery, assets и billing | Живые площадки/провайдеры и paid API не вызывались |
| Acquisition/documents/AI | EIS listing/detail, download/prepare, supervisor/OCR/archive safety, GC/capacity SQL, storage recovery, extraction/chunks/publication | Не каждый parser/DTO просмотрен вручную; default PHP suites текущего regression прошли; самостоятельные acceptance/load/coverage gates этим не заменяются |
| Organization/Reference | entity resolution/uniqueness, streaming import/validation/chunk-upsert | OGRN race пока доказана статически |
| HTTP/Filament/docs/frontend | маршруты, custom/bulk permissions, AzGuard denial path, docs containment, frontend/docs build | agy не вернул результат; дополнительный GPT reviewer закрыл source pass; HTTP/Livewire не равен browser coverage каждого экрана |
| Repository/AI context | tracked/ignored inventory, exact duplicates, naming, ownership и stale instructions | Managed templates требуют исправления у владельца |
| CI/test rig | выбранные suites, guards, artifacts, scenario preservation и carrier wiring | Чистая сборка заблокирована; legacy CI не исполнен на GitHub |
| Production operations | deploy/recovery/backup/secret scripts и volume wiring просмотрены отдельно | Source review; deployment, backup и restore drill не выполнялись |

Полный отчёт acceptance требует самостоятельных E2E/fault gates и двух нагрузочных
профилей WIDTH=10/20 по 1 800 секунд и 1 000 документов. Обычный regression использует
малый corpus; он не доказывает длительную нагрузку. Также не измерены качество реальной
модели на holdout, production failover, restore drill, coverage floors и image vulnerabilities.
Ни один из этих пробелов не считается успешной проверкой.

## Результаты запуска

Основной evidence root: `storage/app/testing/process-core/reports/` (ignored).
Направленные PASS ниже не составляют единый release acceptance packet. Полный regression
`20261105T183421.928233Z-887633` завершился **FAIL**: 3 099 tests, 23 774 assertions,
2 failures, 0 errors/skipped, 1 372.209882 s по JUnit. Real-worker E2E и application behavior
прошли; две ошибки относятся к проверкам:

- `AuditProcessRegressionTest` объединял jitter двух владельцев в неверный bound ≤36 s.
  `RetryAt` задаёт 30–35 s, finalizer отдельно добавляет до 10%. Принята замена проверки
  на read-only actual deadline/DB-clock evidence; production policy не меняется.
- Новый A9 concurrency case ожидал глобально пустой LLM ledger, хотя предшествующий
  planning case коммитит одну строку. Принято сравнение точных before/after ID sets после
  race и replay; ledger не очищается.

Обе корректировки прошли независимый source review. После них quality, направленные
AuditProcessRegression (9 cases) и AnalysisReviewConcurrency (4 cases) прошли. Свежий полный
regression `20261105T190722.559521Z-1058905` также **PASS**: 3 099 tests, 23 785 assertions,
0 failures/errors/skipped, 1 311.327790 s по JUnit. Scenario comparison сохранил все 2 014
исходных cases из baseline inventory.

| Проверка | Результат / evidence run |
|---|---|
| Итоговый полный regression | PASS: 3 099 tests / 23 785 assertions, 0 failures/errors/skipped; `20261105T190722.559521Z-1058905`; 2 014 исходных cases сохранены |
| Итоговый quality и адресные повторы | Quality PASS `20261105T190659.233598Z-1049380`; AuditProcessRegression 9 PASS `20261105T190714.762849Z-1055784`; review concurrency 4 PASS `20261105T190718.814113Z-1057402` |
| Чистый `core-test-up` | FAIL: pinned MinIO pull; `20261105T164036.704412Z-166180`. A2 открыт |
| Existing local image, исходные guards | PASS `20261105T164234.879629Z-174254`; это не fresh image build |
| Environment и clean installs | `core-test-env` PASS `20261105T164327.829481Z-180759`; два совпавших installs PASS `20261105T164448.789828Z-197314` |
| Первый полный regression | FAIL: 3 091 tests, 23 381 assertions, 2 failures, 0 errors/skipped, 1 400.9 s; `20261105T164601.723975Z-233701`. Причины: owning acceptance hash и A10. Это не final-candidate packet |
| Financial recovery | 25 scenarios PASS `20261105T171051.886806Z-284598` |
| DocsIntegrity | PASS `20261105T171118.801809Z-356094` |
| AnalysisFeeder refactor | Planning 4 PASS `20261105T172513.476267Z-437105`; concurrency 3 PASS `20261105T172517.585324Z-439023`; quality PASS `20261105T172520.638913Z-440525` |
| Вторая волна | Documents 236 PASS `20261105T173818.177804Z-507130`; AI 268 PASS `20261105T174207.420765Z-541629`; planning concurrency 3 PASS `20261105T174532.477473Z-571173`; document feeder concurrency 4 PASS `20261105T174535.900284Z-572685`; quality PASS `20261105T174545.549568Z-574792` |
| Третья волна | Acquisition 137 PASS `20261105T180046.165028Z-659451`; documents 236 PASS `20261105T180238.478727Z-682490`; quality PASS `20261105T180023.557415Z-652521` |
| AI packet третьей волны | 267 passed / 1 source-hash FAIL `20261105T180628.025929Z-715802`; после pin correction AcceptanceManifest 4 PASS `20261105T181047.515950Z-747827`. Это не повтор целого AI gate |
| Quality после четвёртой волны | PASS `20261105T182835.232435Z-838205` |
| Daemon readiness | 17 PASS `20261105T182853.838018Z-845802`; старые 14 inventory cases сохранены, добавлены 3 |
| Review concurrency/faults | 4 PASS `20261105T182857.644359Z-847391` |
| Gold recorder | 4 PASS `20261105T182901.390347Z-848828` |
| UI behavior/guards | 8 PASS `20261105T182904.861325Z-850418` |
| Paired A10 memory probe | PASS `20261105T182954.713748Z-856904`, artifact `audit-horizon-memory.jsonl`; baseline `20261105T181205.841760Z-754716`. Probe полностью reverted |
| Полный regression после четвёртой волны | FAIL: 3 099 tests / 23 774 assertions, 2 failures, 0 errors/skipped; `20261105T183421.928233Z-887633`. Причины и корректировки проверок описаны выше; последующий полный повтор PASS |
| Исходный real-worker E2E без probe | 3 PASS `20261105T183353.812412Z-884300`, прежний master limit и assertions |
| CLI behavior | 5 файлов / 25 cases PASS: GoldBuild `20261105T183320.982478Z-873085`, ScreeningGold `20261105T183324.471002Z-874657`, PromptSuggest `20261105T183327.894598Z-876083`, DatasetExport `20261105T183331.417161Z-877614`, VerifyDocumentUid `20261105T183334.724308Z-879201` |
| Importer policy | DatasetImport 10 PASS `20261105T183338.122404Z-880664`, GoldImport 7 PASS `20261105T183347.069242Z-882519` |
| Финальная проверка форматирования manifest и отчёта | Quality PASS `20261105T193147.064641Z-1178286`; AcceptanceManifest 4 PASS `20261105T193201.289767Z-1184761`; DocsIntegrity PASS `20261105T193204.951064Z-1186309`; fixture manifest и diff whitespace checks PASS |
| Frontend/docs build | `pnpm build` PASS, 56 modules; `documentation:build`/`documentation:verify` PASS, 354 distribution files |
| Dependency audits | FAIL по advisories; locks не менялись. Машинный результат: `storage/app/testing/repository-audit-2026-11-05/dependency-audits.json` |

Независимые source reviews четырёх волн не нашли блокирующих замечаний. Отдельный
`core-test-report` ранее FAIL (`20261105T171617.438792Z-393958`); полный acceptance packet,
load/coverage, clean image build и production readiness по перечисленным PASS не заявляются.

После полного regression изменены только этот отчёт и форматирование основного fixture manifest.
JSON values и порядок ключей manifest сохранены побайтно в канонической сериализации;
восстановлен исходный отступ 4 spaces, diff сокращён с 7 270 до 8 изменённых строк.
Raw SHA manifest изменился (`669b5f…` → `3f15ef…`), поэтому evidence предыдущего прогона
остаётся привязанным к исходным байтам и не переименовывается в новый release packet.

Сравнение 2 643 входов с frozen full-run snapshot подтвердило: после regression изменились
только отчёт и JSON whitespace manifest; application/test code полностью совпадает. Финальная
сборка документации и проверка distribution из 354 файлов прошли.
