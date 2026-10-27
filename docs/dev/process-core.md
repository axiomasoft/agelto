---
audience: dev
type: reference
status: living
---

# Process core: ledger исполнения

PostgreSQL — authority исполнения (D2): work, intents, attempts, admission, journal и outbox
живут в `prc_*` таблицах Process ledger; Redis/Horizon только доставляют. Точный DDL,
staging и ownership каждой колонки — [Process schema](process-schema.md).

## Состав baseline P1.6

| Слой | Таблицы | Назначение |
| --- | --- | --- |
| Control authority | `prc_control_scopes` | Всегда существующая scope row: default и manual override в одной строке; `prc_process_controls` нет |
| Handler authority | `prc_process_handler_holds` | Materialized eligibility unique(stage, handler_version); held требует signature/incident |
| Capacity | `prc_process_lanes`, `prc_admission_groups`, `prc_process_reservations` | W/C lanes; group = work + dispatch generation + полный lane set; members удаляются вместе с group |
| Runs | `prc_process_runs`, `prc_process_run_work` | Discovery `collecting → complete/incomplete`; provenance links required/observes |
| Subjects | `prc_crawl_runs`, `prc_crawl_blocks`, head/cycle поля `prc_procurements`/`prc_documents` | Реальные owners listing/detail/status_refresh/download без собственного execution state |
| Work | `prc_operation_intents`, `prc_process_work`, `prc_process_attempts` | Intents до work FK; один owner FK стадии; generation/token/lease; immutable attempts |
| Journal/delivery | `prc_process_transitions`, `prc_process_outbox`, `prc_process_incidents` | Transition + projection intent в той же tx; kind-scoped outbox |
| Runtime | `prc_process_daemon_heartbeats` | Liveness instance каждого daemon |
| Requests | `prc_request_attempts`, `prc_observations`, `prc_observation_applications` | Durable факты внешних send; writers и network FK — P2.1–P2.6 |

Обязательные инварианты DB, проверяемые `tests/Feature/ProcessCore/Execution`:

- stage CHECK work/intents/reservations/handler_holds = `SchemaSupportedStages`
  (listing, detail, status_refresh, download); prepare/screening/extraction/publication и их FK
  добавляют P3.1/P3.8;
- owner tuple: ровно одна непустая owner column своей стадии, FK на реальный subject;
- running ⇔ token+lease, admitted ⇔ claim deadline, deferred ⇒ due_at, due_at ⇒ deferred;
- `provider_recovery_due_at` не NULL ровно при blocked(`provider_result_unknown`); его partial
  index — отдельный reconciliation schedule, не business due;
- `handler_fault_limit` blocked без due; budget считается по finished attempts с fault_signature
  через `prc_process_attempts_work_fault_idx`, отдельного counter нет;
- nullable JSON/version pairs и положительные schema versions; `item_failures` в 0..3;
- partial unique: один active intent на subject, один active admission group на work, один
  нерешённый incident на fingerprint, unique entity sequence transitions;
- `ON DELETE CASCADE` только `prc_process_reservations.admission_group_id`.

## Writers

Все writers — `app/Repositories/Procurement/Process` и `Crawler/CrawlStoreRepository`, explicit SQL,
участвуют в транзакции вызывающего (`CallerTransaction`), ID создаются до неё:

| Repository | Операции P1.6 |
| --- | --- |
| `RunStoreRepository` | `storeRun`: run collecting + run transition + projection intent |
| `CrawlStoreRepository` | immutable crawl run/block snapshot; block наследует filter hash run |
| `WorkStoreRepository` | `storeIntent`, `storeWork` (ready, work_key = `WorkIdentity`, owner из `SchemaSupportedStages`), `linkRun` |
| `TransitionRepository`/`OutboxRepository` | transition и `project:<event_id>` outbox |
| `ControlRepository` | `ensureScopes`: полные цепочки scopes, существующие FOR SHARE в sorted `scope_key`, INSERT по unique key; parent/key immutable |
| `HandlerAuthorityRepository` | `register`: healthy row unique(stage, handler_version) только для schema-supported stage; повтор не снимает held |
| `LaneRepository` | `syncLanes`: lanes FOR UPDATE в порядке id, INSERT parent перед child; меняет только W/C/low/refill/policy_version |

## Authority rows

Authority существует до первой работы: отсутствие row не означает enabled. `ProcessAuthoritySync`
идемпотентно создаёт отдельными короткими транзакциями:

- control scopes каждой schema-supported stage: `system` → `acquisition`/`documents` →
  `acquisition:stage:<stage>` либо `documents:stage:download`; default-enabled хранится в той же row,
  что manual override;
- lanes из `required_lanes(stage)` (`ProcessPolicy`): `acquisition` + `acquisition.<stage>` для
  listing/detail/status_refresh, `download` для download; lanes будущих stages не создаются;
- handler-version row при registration (`registerHandler`), новая версия — своя row.

`config/process.php` — versioned policy v1: W/C/low/refill каждой lane (execution#lane-defaults) и
`max_handler_faults_per_work_version` = 3 (execution#handler-fault-budget). `ProcessPolicy`
отвергает неполную конфигурацию с точным config key. Clean install/seed authority rows не создаёт;
вызов sync из boot daemons подключает P1.10.

Models `app/Models/Procurement/{Process,Crawler,Network}` — read models с typed casts; mass
assignment закрыт, head/cycle поля procurement/document не fillable. Heartbeat, checkpoint,
finalize, recovery, handler hold detector, outbox consumers и daemons добавляют P1.8–P1.11; до
этого runtime consumers ledger — только fixture handlers тестов.

## Admission, claim и controls (P1.7)

Механика работает только на fixture handlers: `StageRegistry` (singleton composition registry)
пуст в production, поэтому реальные acquisition/AI handlers не получают grants. Регистрация
разрешена лишь schema-supported stage; handler version — версия исполняющего кода.

**Full prefix.** `AuthorityRepository::readAuthoritySnapshot` — unlocked pre-read (work input,
control closure, executing handler authority, все required lanes, subject heads, active group).
`lockPrefix` берёт tiers строго по порядку: control scopes `FOR SHARE` (byte order `scope_key
COLLATE "C"`) → handler-version row `FOR SHARE` → все lanes `FOR UPDATE` (sorted id) → subjects
`FOR UPDATE` (table key, PK) → work `FOR UPDATE` (`SKIP LOCKED` только у admission). Изменившийся
состав/head после pre-read — rollback + reload, не поздний lock. Каждая operation — одна
`ProcessTransaction` (lock_timeout 2s, statement_timeout 5s, до 3 попыток на 40P01/40001/55P03).

**Control closure work** = `system` → `acquisition|documents` → `<purpose>:stage:<stage>` плюс scope
cohort: `<purpose>:platform:<code>` (cohort `platform:<code>`) либо `<purpose>:cohort:manual`.
Admission scopes не создаёт: `WorkFeeder` создаёт новые cohort scopes отдельной `ensureScopes` tx
(dynamic cohort arrival). `ControlRepository::setMode` — optimistic pause/resume одной row:
ancestors `FOR SHARE`, сама row `FOR UPDATE`, тот же sorted порядок; control transition с
`entity_seq = control_version`. Resume снимает только manual mode этой row, handler holds не трогает.

**Admission** (`AdmissionRepository::admit`, bp-sql-admit). Под locks повторяются eligibility
(ready либо deferred с наступившим `due_at`), отсутствие active group, input/head/intent
(`SubjectLockResolver`), controls, handler health, authoritative COUNT reservations каждой lane
(`>= W` — нет grant), fairness cursor parent lane и cohort cap. Grant атомарно: group +
точный required lane set (hash `ProcessPolicy::requiredLaneSetHash`), `dispatch_generation+1`,
`dispatch_claim_until` (claim visibility stage), transition + projection intent, `work_wake`
`wake:<work>:<dg>`, новый cursor. Stale input — fenced `superseded(input_invalidated)` с
generation+1 и release всей group. Ready work с active group — `admission_membership_mismatch`
incident.

**Feeder** (`WorkFeeder::tick(parentLane)`): refill только при parent outstanding ≤ low_watermark,
не больше refill_max grants. Durable cursor v1 `{last_cohort, last_stage_by_cohort}` на parent
lane: RR между eligible cohorts → RR sibling stages внутри cohort → bounded candidates
(`candidate_limit` после выбора cohort/stage). Внутри stage aged work (immutable `created_at`
старше `age_seconds` 900) первым по возрасту без priority, non-aged platform — priority,
created_at, id; manual — FIFO. При ≥2 eligible cohorts новый grant cohort требует
`active(parent, cohort) < ceil(W/2)`; выданные slots не отзываются, единственный cohort использует
весь W.

P19-C1: перед admission pre-read собирает по одному authority snapshot для каждой ready/due
пары stage/cohort. Их control closures, handler rows и lanes входят в общий ранний lock set
(каждый tier отсортирован); subjects/work блокируются только для текущего candidate. После
capacity locks repository заново читает ready/due пары и проверяет их по locked control modes,
handler health и W. Изменение числа eligible cohorts требует нового выбора (`CursorMoved`),
новая пара без ранних locks — reload (`SnapshotChanged`). Feeder ограниченно повторяет выбор.
Переданное вызывающим число cohorts не разрешает обход cap; несвязанный заполненный sibling
lane не запрещает grant текущей стадии.

**Claim** (`WorkStoreRepository::claim`, bp-sql-claim): тот же prefix без `SKIP LOCKED`.
Terminal → `WorkTerminal`; running той же delivery → `DuplicateDelivery`; иная generation или
не admitted → `StaleDelivery`; истёкший `dispatch_claim_until` → `ClaimExpired`; stale input →
supersede + `WorkTerminal`; pause → `Paused`; held/незарегистрированный handler → `HandlerHeld`
(group не меняется, admitted→blocked делает reconciler P1.11); неточный member set →
`InvariantViolation` + incident отдельной tx без repair. Если running COUNT любой lane ≥ C —
`CapacityWait`: group сохраняется, `dispatch_generation+1`, новый deadline и обычный wake через
5–10 s; старая delivery становится stale, item budget не тратится. Успех: generation+1, случайный
token, DB lease (`process.stages.<stage>.lease`), immutable attempt (handler version, sha256
token), вся group и members `running`, transition; `ClaimResult` несёт `ExecutionLease`.

P19-C1 exact membership включает `work_id`, `stage` и state каждого member, точный lane set/hash,
delivery generation и attempt linkage (у admitted group attempt отсутствует). Claim/finalize
проверяют этот guard до supersede/release при изменении input/head. Повреждённая group остаётся
неизменной, открывается `admission_membership_mismatch`; input invalidation не ремонтирует members.

Проверки: `tests/Feature/ProcessCore/Execution/T11AdmissionFairnessTest.php` (parent+child W,
refill, cohort→stage RR, age/priority/manual FIFO, cap и dynamic cohort, deferred/blocked,
pause/handler hold, supersede), `T09ClaimDeliveryTest.php` (claim, duplicate/stale/expired/
terminal delivery, parent+child C CapacityWait, pause/hold, membership incident, порядок lock
tiers, setMode) и многопроцессный `tests/Integration/ProcessCore/Concurrency/T24PauseClaimConcurrencyTest.php`
(pause против 10 параллельных claims без deadlock и без grant после commit pause; два feeder ≤ W).
`make core-test-execution` выполняет эти каталоги после clean reset. Recovery (T09 полностью),
queue/daemons и document/load T11/T16 — следующие пункты.

## Lease, checkpoint и fenced finalize (P1.8)

**ExecutionContext** (`Dto/Procurement/Process`, D13/bp-heartbeat) — одна final mutable class на
claimed attempt с внедрёнными `Contracts/LeaseKeeper` (DB-порт, по умолчанию
`WorkStoreRepository`) и `Contracts/ProcessClock` (DB clock + monotonic + sleep). Handler вызывает
`tick()` между частями и в idle wait (`progressCallback()`); heartbeat CAS — не чаще
`process.execution.heartbeat_seconds` (15) по monotonic clock. Typed выходы
(`Exceptions/Procurement/Process`): `LeaseLost` (CAS 0 rows, отказ checkpoint, локальный safety
deadline = последний renewal + lease − `lease_safety_seconds`), `SliceExhausted`
(`process.stages.<stage>.slice`), `DependencyUnavailable` (transient DB по
`DatabaseFailureClassifier`). `canStart(needed)` требует полный timeout + finalization reserve,
timeout не укорачивается; `sleepUntil(DbInstant)` спит частями ≤5 s с tick. Alarm/fork heartbeat
нет; `ExecutionContextFactory` открывает context по policy stage. Policy требует lease > slice и
lease > 2·heartbeat + safety.

**Heartbeat** (`WorkStoreRepository::heartbeat`) — autocommit CAS id+generation+token+running и
`lease_until > clock_timestamp()`, без других locks; истёкший или отозванный lease не оживляется.
**Checkpoint** — subject set `FOR UPDATE` (subset prefix, mutable subject не берётся SHARE) → work
под lock → CAS с expected `checkpoint_seq` и живым lease **до** domain progress
(`StageResultRepository::storeProgress`). Merged `WorkCheckpointEnvelope`: новый stage progress +
текущий locked provider recovery plan. Исходы `Saved | LostOwnership | StaleCheckpoint |
InputSuperseded`; любой отказ или сбой progress write откатывает всю tx.
Expiry между live precheck и checkpoint UPDATE даёт typed `LostOwnership`: work CAS не изменил
строк, domain progress и attempt checkpoint не записываются; programming fault budget не тратится.

**WorkFinalizer** (`finish`/`complete`/`settle`/`finishHandlerFault`) исчерпывающе dispatch-ит пять
closed `StageOutcome`; неизвестный вариант — `LogicException`. `WorkFinalizationRepository` под тем
же полным prefix (control/handler только `FOR SHARE`, никакого UPDATE этих rows) до domain writes
проверяет generation+token+running, immutable input, subject head/intent и exact group/attempt.
`lease_until` для final commit не требуется (D9): истёкший, но не отозванный owner коммитит готовый
результат; revocation/cancel/supersede, уже увеличившие generation, дают `LostOwnership` без
единой записи. Тот же owner при сменившемся head — fenced `superseded(input_invalidated)`, attempt
`superseded`, старый result не публикуется. Pause/handler hold не отбрасывают полученный результат.
Одна tx: stage result (`StageResultRepository::storeResult`) + ready successors (dedup по
`work_key`, run links всех runs, cancelled run без новых required) + work state/reason/due + attempt
`terminal_kind` + release всей group + transition/projection + coalesced `feed:<parent lane>:<5 s>`
(`ON CONFLICT DO NOTHING`).

| Outcome | state | due / reason |
| --- | --- | --- |
| `StageSucceeded` | succeeded, checkpoint очищен | — |
| `StagePartial` | ready, merged envelope, `checkpoint_seq+1` | — |
| `StageDeferred` | deferred | `max(RetryAt, now)` + jitter ≤10% оставшегося интервала |
| `StageBlocked` | blocked, due NULL | `provider_result_unknown` только с pinned `blocked_unknown` plan, `provider_recovery_due_at` +30 s |
| `StageFailedItem` 1/2 | deferred(`item_retry`) | +30 s, затем +120 s; `item_failures+1` |
| `StageFailedItem` 3 | failed_item, checkpoint очищен | — |
| handler fault < 3 | deferred(`handler_fault`) | +60 s; fault_signature на attempt |
| handler fault = 3 | blocked(`handler_fault_limit`) | due NULL; incident `handler_fault_limit` |

Handler fault budget — finished attempts с fault_signature этой work и executing handler version
(`ExecutionLease::handlerVersion`), без окна времени; rollback finalize не расходует budget, item
budget не трогается. Global distinct-input detector и снятие holds — P1.11/P1.17.

`StageRegistry::bindFinalization(stage, codec, repository)` — composition seam stage codec
checkpoint/result и finalization repository; в production пуст, tests привязывают fixtures.
Проверки: `tests/Feature/ProcessCore/Execution/T09FinalizeFenceTest.php` (атомарный success и
successors, D9 late finalize, revocation/cancel → ноль записей, new head → superseded, checkpoint,
outcome table, item budget, lock tiers без SHARE upgrade), `T25ExecutionContextTest.php` (fake
LeaseKeeper/clock) и многопроцессный
`tests/Integration/ProcessCore/Concurrency/T09FinalizeFenceConcurrencyTest.php` (оба порядка
finalize/revocation, cancel/new head, lost checkpoint CAS, pause против 10 finalize, fault budget
и journal rollback); `make core-test-concurrency`. Revocation в них — контролируемая fixture под
тем же full prefix, не RecoveryReconciler (P1.11).

Отрицательные регрессии P19-C1: `P19CorrectionRegressionTest.php` проверяет чужие work/stage
members при claim/finalize, corruption вместе с head drift, missing/extra members и чужой attempt,
stale eligibility count при ready/due/resume и реальный нулевой checkpoint CAS после expiry.
`T11AdmissionFairnessConcurrencyTest.php` использует отдельные PG процессы и lock-wait barrier:
ready/resume коммитится между selection и grant без изменения parent cursor. Это тесты текущего
correction scope; фактический результат их запуска фиксирует coordinator в V1/V3.

## Queue envelope, relay и daemons (P1.10)

Redis и Horizon — только delivery: authority остаётся в PostgreSQL ledger, у job нет ни retry, ни
ожидания, ни восстановления. Recovery после потери Redis (expired admitted/running scanners) и
T31 целиком — P1.11/P1.12; здесь доставка, relay, feed и скелет reconcile.

**ExecuteWorkJob** (`Jobs/Procurement/Process`) — единственная queue delivery: scalar envelope
`work_id`, `dispatch_generation`, `message_id`, `queue_schema_version` (`DeliveryEnvelope`), без
моделей и credentials. Одна попытка: `#[Tries(1)]` и `#[MaxExceptions(1)]` (в payload
`maxTries=1`, `maxExceptions=1`; свойства `$tries`/`$maxExceptions` запрещает
`JobRetryContractGuardTest` для `Jobs/Procurement`), `failOnTimeout`, без release/backoff/delay,
`ShouldBeUnique`, `WithoutOverlapping`, `RateLimited`, Bus chain/batch. **Public `$timeout`**
назначает relay из `StageQueueMap` до push — Laravel кладёт его в payload; метода `timeout()` нет.
`handle` вызывает `WorkRunner::runDelivery`: transient DB failure (`DatabaseFailureClassifier`)
ACK-ится, durable scanners восстанавливают; constraint/schema/programming SQL errors пробрасываются
видимо. `failed()` только пишет sanitized telemetry (`ProcessLog`) и ledger не меняет.

**WorkRunner** — envelope → claim → только `Claimed` вызывает handler из `StageRegistry`
(`bindHandler`/`handler`, без выбора по stage) → единственный `WorkFinalizer`. До generic
`Throwable` различаются: `LeaseLost` (без публикации, work остаётся durable running),
`SliceExhausted` (устойчивый checkpoint → `StagePartial`, иначе deferred `slice_exhausted`),
`DependencyUnavailable` (storage/converter/provider → deferred соответствующего reason; database —
без finalize; неизвестное имя — дефект кода). Отмена `CancelWork` во время исполнения приходит как
`LeaseLost`: heartbeat CAS на новом generation возвращает 0 строк (`ExecutionContext::tick`); отдельного
типа «cancelled» для runner нет. Только неожиданный programming Throwable идёт в
`finishHandlerFault` (locked budget execution#handler-fault-budget). Сбой finalizer handler второй
раз не запускает. Неподдерживаемая `queue_schema_version` не исполняется: incident
`queue_schema_unsupported`, работа остаётся за scanners.

**StageQueueMap** — единственный источник connection/queue/timeouts. `process.queues` задаёт
connection, queue и job timeout stage; Horizon timeout берётся из единственного supervisor этой
connection+queue (`tries=1`), `retry_after` — из фактической queue connection, slice и lease — из
`ProcessPolicy`. Цепочка `slice < job < Horizon < retry_after < lease` проверяется при сборке и
отвергается с ключом config. Redis `default` connection и `supervisor-1` остаются для
notifications/mail.

| stage | connection | queue | job | Horizon | retry_after | lease |
| --- | --- | --- | --- | --- | --- | --- |
| detail, status_refresh | `process_short` | `process-acquisition` | 110 | 120 | 150 | 180 |
| publication | `process_short` | `process-publication` | 110 | 120 | 150 | 180 |
| listing | `process_long` | `process-listing` | 260 | 280 | 330 | 360 |
| prepare | `process_long` | `process-prepare` | 260 | 280 | 330 | 360 |
| download | `process_download` | `process-download` | 320 | 340 | 390 | 420 |
| screening, extraction | `process_ai` | `process-ai` | 290 | 310 | 360 | 390 |

Supervisors (`maxProcesses` = C+1, authority остаётся ledger): listing 3, acquisition 5, download 5,
prepare 3, AI 11, publication 3. Horizon `stop_grace_period` ≥ 370 s (максимальный timeout 340 + 30)
задаётся compose/deploy и в PHP-конфигурации не выражается; старый больший grace сохраняется, пока
живы legacy long jobs. Legacy `redis_prepare`/`redis_crawl_long` и их supervisors удаляют owning
cutovers.

**Relay и outbox consumers** (`OutboxRepository::claim/markPublished/releaseClaim/quarantine`).
Каждый consumer claim-ит только свой `kind` одним statement `FOR UPDATE SKIP LOCKED` с fresh claim
token (batch ≤100, claim 30 s), side effects выполняет вне tx и помечает published тем же token.
`RelayDaemon` (`work_wake`): падение до publish повторяет сообщение, после publish до mark — duplicate
delivery, безопасный по dispatch generation; перехваченный claim не может mark чужое. Publish
failure снимает claim и оставляет сообщение с кодом `redis_publish_failed`. Сообщение, которое relay не
умеет исполнить (payload/schema/маршрут), не помечается published: `quarantined_*` код, incident
`outbox_quarantine`, откладывается на час, readiness остаётся `outbox_quarantined`.
`FeederDaemon` (`feeder_wake`) — периодический feed tick 2 s ±20% по parent lanes + собственные
wake; wake помечается после принятого tick. Coalescing wake одного 5-секундного bucket —
`ON CONFLICT DO NOTHING` (0 вставленных строк, даже если существующий wake уже published, — успех).
`ReconcileDaemon` — цикл tick 5 s ±20% с зарегистрированными `ReconcileCategory`; категории recovery
регистрирует P1.11, без них daemon ничего не восстанавливает и этого не заявляет.
`ProjectorDaemon` не создан: `DaemonKind::Project` не активирован до P1.14–P1.18.

**Daemons и readiness.** `php artisan process:daemon relay|reconcile|feed` (`DaemonLoop`): свой instance
UUID на старт, TERM/INT ставят stop-флаг (graceful exit 0), recycle по `max_runtime_seconds` 3600 и
`memory_limit_mb` 256 (restart — docker), ошибка tick не убивает loop: sanitized лог, код ошибки в
heartbeat, backoff 1→30 s. Heartbeat (DB clock) каждые 10 s, в том числе во время backoff. Liveness
— instance со свежим `last_tick_at` (< 30 s); readiness (`DaemonReadiness`, `process:daemon <d> --health`)
отдельно: `Healthy` — есть живой instance без текущей ошибки, `Degraded` — живые только с ошибкой,
`Down` — нет свежего heartbeat. Readiness требует по instance каждого активированного daemon.
Feed-daemon при старте вызывает `ProcessAuthoritySync::ensureBaseline`. Scheduler остаётся
maintenance/schedule producers и не доставляет wake.

Проверки: `T08QueueEnvelopeTest.php` (таблица `StageQueueMap` и отрицательные конфигурации, payload
по реальному `createPayload` каждой connection, publisher на connection+queue, publish-before-mark
duplicate, Redis failure, quarantine, feeder coalescing pending/published, feed daemon),
`T08WorkRunnerDeliveryTest.php` (job/runner исходы на fixture handlers и реальном ledger),
`T08DaemonReadinessTest.php` (heartbeat/readiness, loop backoff/recycle, command exit codes) и
многопроцессный `tests/Integration/ProcessCore/Concurrency/T08OutboxClaimConcurrencyTest.php` (два
relay claim-ят непересекающиеся сообщения, чужой kind не затронут). Схема не менялась. Реальная
публикация в Redis и redelivery после потери Redis проверяет P1.11 (`T31RecoveryReconcileConcurrencyTest`).

## Recovery и управление исполнением (P1.11)

Схема не менялась (DDL P1.6 уже содержит `provider_recovery_due_at`, `fault_policy_checked_at` и
индексы). Единственный recovery owner — `RecoveryReconciler` (`Services/Procurement/Process`):
пять категорий с tick5s и отдельный `invariant` с интервалом 60s, которые `process:daemon reconcile`
регистрирует при запуске (вместе с `ensureBaseline`, как feed). Recovery batch ≤ 50; на каждый work отдельная
`ProcessTransaction` под полным prefix control SHARE → handler SHARE → capacity UPDATE → subjects
UPDATE → work UPDATE SKIP LOCKED, затем повтор всех predicates (state, deadline, input/head,
membership, controls). Изменившийся snapshot — rollback и новый pre-read (≤ 3 раз), а не поздний
lock; повреждённая group/membership открывает incident и не чинится молча. Queue delivery и Redis
здесь не участвуют: `published_at` старых сообщений не authority.

| Категория | Что делает |
| --- | --- |
| `expired_running` | `lease_until` истёк ≥ 2 s назад и в locked tx `lease_until <= now`: attempt `interrupted`, generation+1 (владелец отозван), вся group released, → `ready` (checkpoint сохранён), `blocked(paused\|handler_hold)` либо `superseded` при сменившемся input. Продлённый heartbeat и уже принятый finalize побеждают (D9). |
| `admitted` | Истёкшая delivery: новый `dispatch_generation` у той же group + обычный `wake:<work>:<n>`, без attempt. Committed pause/held handler: `blocked`, вся group released. Stale input — `superseded`. |
| `blocked_release` | `blocked(paused\|handler_hold)` → `ready`, только если ВСЕ scopes enabled и executing handler healthy; pinned provider plan сохраняется. `handler_fault_limit` и `provider_result_unknown` не освобождаются. |
| `blocked_provider` | EXR-03, независимо от остальных и без queue/receipt notification. Selector `provider_recovery_due_at <= now`, `ORDER BY due, id LIMIT 50`. |
| `handler_fault_sweep` | Historical detector: unchecked finished fault attempts, окно 5 минут от `finished_at` кандидата (а не от now), ≥ 3 distinct `input_hash` одной signature и версии → `healthy → held` + incident `handler_hold`; marker `fault_policy_checked_at` ставится в той же tx, повтор идемпотентен, уже held версия не перезаписывается. |
| `invariant` | Первый tick после старта, затем не чаще одного раза в 60 monotonic секунд. До 50 ещё не зарегистрированных нарушений за проход; healthy pass возвращает 0 изменений. Независим от queue delivery и пяти recovery categories. |

**Provider recovery.** `ProviderRecoveryResolver` (`Contracts`) — единственный способ превратить
платные факты в `ProviderRecoveryPlan` (documents-ai#s08): `inspect` — unlocked pre-read (object I/O
допустим здесь), `settleLocked` — идемпотентный settlement original reservation под locks,
`revokeUnclaimedLocked` — отзыв ещё не claimed send при CancelWork. Baseline-реализация
`NoProviderOperations` честна только пока provider stages не активны; P3.8–P3.14 обязан привязать
настоящий resolver через `RecoveryReconciler::bindProviderResolver` до первого admission таких
stages. Исходы: `blocked_unknown` → `blocked(provider_result_unknown)` с pinned plan в
`WorkCheckpointEnvelope` и `provider_recovery_due_at = now + 30 s` (`due_at` NULL); verified
`resume_from_receipt`/`resume_provider_asset`/`known_retry_eligible` → тот же work `ready` с plan
(или `blocked(paused|handler_hold)` с сохранённым plan), settlement, `checkpoint_seq+1`, transition
и projection атомарны. Нет verified outcome, недоступное хранилище (`DependencyUnavailable
('storage')`) или отсутствующий pinned linkage (incident `provider_unknown_linkage_missing`) —
unknown и reserve сохранены, меняется только reconciliation due. Cancelled/superseded после pre-read:
только settlement, без возобновления и публикации. Работа без checkpoint получает
`StagePayloadCodec::initialCheckpoint()` как stage progress envelope.
Locked predicates включают logical/physical IDs, purpose, input/recipe hash, request epoch и
checkpoint sequence; другой receipt не заменяет pinned plan. Storage failure сдвигает due под тем
же полным prefix. При недоступном resolver уже сохранённый unknown остаётся unknown; иной
durable provider plan получает `blocked(policy_review)`, сохраняя envelope и запрещая новый send.

**Управление.** `Actions/Procurement/Process`: `CancelWork` — hard cancel nonterminal work (generation+1,
attempt `cancelled`, group released, reason/actor в `details` transition, optimistic
`transition_seq`); `CancelRun` — отмена намерения run (`cancel_requested_at`, `collecting →
incomplete(cancelled)`), shared work других runs не отменяется, cancelled run не получает новых
required links; `RetryHandlerFaultedWork` — выход из `blocked(handler_fault_limit)`: прежний work →
`superseded(manual_retry)`, прежний intent → `terminal`, новый manual intent (`intent_key` =
`commandId`, поэтому повтор команды — `Duplicate`) и successor со свежим budget, required links
переносятся (прежние становятся `observes`). Поддержаны все четыре schema-supported stages.
Immutable input не меняется: `WorkIdentity` сохраняет отдельные `manual_retry.intent_id` и
`retry_of_work_id`, которые входят в новый key. Обычные identities сохраняют прежние bytes/key.
Если у исходной работы нет intent, консервативная policy identity нового manual intent — полный
unchanged input hash; существующие policy hashes наследуются. Один command нельзя применить к
другой work. Cancel проверяет exact group/attempt до mutation; CancelRun сохраняет actor/reason в
audit, новые required links после cancel запрещены под run lock. Shared successor повышает
`observes` до `required`, когда появляется required producer, и не понижает эту роль обратно.
RBAC, UI-версионирование и ValidateHandler/Pause/Resume actions — P1.17.

Проверки: `T09RecoveryReconcilerTest` (expired running после простоя > 5 мин, admitted redelivery,
pause/hold, input drift, heartbeat/checkpoint старого владельца), `T22BlockedProviderRecoveryTest`
(EXR-03: late receipt без queue delivery, все исходы, cancel/drift после pre-read, pause, storage
down, > 50 кандидатов), `T17HandlerFaultRecoveryTest` (historical detector, fault limit, manual retry
dedup), `T06WorkControlTest` (CancelWork/CancelRun/shared links), многопроцессные
`T09FinalizeFenceConcurrencyTest` (оба порядка finalize/recovery и cancel на настоящих
`RecoveryReconciler`/`CancelWork`) и `T31RecoveryReconcileConcurrencyTest` (два reconcile daemon,
реальный Redis FLUSHALL на изолированном rig, redelivery, одна accepted финализация).
Readiness/Compose дополнены P1.10 D45 ниже; projector принадлежит P1.14–P1.18.

**Periodic invariants (P1.11 D45, bp-sql-recover п.6).** `InvariantScan` добавлен последней категорией;
предыдущие пять имён/порядок и tick5s сохранены. `InvariantRepository` берёт `system FOR SHARE`,
затем все текущие lanes `FOR SHARE` в порядке id. Один SQL snapshot проверяет точный required lane
set/hash из `ProcessPolicy`, work/stage/state members, group/work dispatch generation, active
group ↔ admitted/running work, unfinished attempt count, attempt/work/generation/token linkage и
finished/terminal consistency, отсутствие members у released group, authoritative COUNT по W/C.
Capacity writers сериализуются с этим чтением; heartbeat не меняет проверяемые predicates.
Current core имеет только lane capacity: storage-transfer/reservation ledgers ещё не установлены,
поэтому storage-transfer consistency сейчас неприменима. Их появление требует расширить этот scan
под тем же capacity tier; будущие storage/provider/projector handlers здесь не активируются.

Read transaction полностью коммитится **до** следующей короткой mutation. В ней первым берётся
`system FOR UPDATE`; существующий versioned `ControlRepository::setMode` ставит admission pause,
а `IncidentRepository` записывает typed `invariant_*` incident/evidence + incident transition и
projection. Pause и incident/journals/outboxes атомарны на каждое finding. Failure откатывает обе
записи; crash между чтением и mutation оставляет нарушение следующему sweep. Existing manual pause
не переписывается. Это консервативная system admission hold: running корректный finalize разрешён,
новые grants запрещены. При concurrent Resume следующий scan повторно ставит hold, если нарушение
сохранилось. Не выполняются auto-increase limits, partial member delete или silent repair;
любой будущий repair обязан доказать state и взять полный execution prefix.

Active incident fingerprint `(predicate, subject)` служит durable progress: уже записанные findings
при действующей pause исключаются **до LIMIT50**, поэтому healthy prefix/старые нарушения не
вытесняют новые и restart не теряет batch progress. Два scanner instances коммитят один incident
и одну pause без роста occurrences на повторе. Исторический incident не закрывается автоматически;
evidence описывает обнаруженный согласованный snapshot, даже если последующий repair уже исправил
его. Processed count — только реальные incident/hold changes. Failed scan не продвигает cadence;
вложенный вызов не берёт read locks и не продвигает cadence: scan остаётся due до standalone tick,
чтобы caller не сохранил SHARE locks за границей read phase и не сделал поздний upgrade.

`T09InvariantScanTest` содержит healthy, cadence60/independent5s, negative fixtures каждого
predicate, >50/restart/progress, manual pause/resume и rollback incident journal. Настоящие PG
barriers `T09InvariantScanConcurrencyTest` ставят competitor control SHARE и capacity UPDATE между
read и mutation: capacity освобождена, пока scanner ждёт отдельный control UPDATE. Второй barrier
перекрывает read snapshots двух scanners и проверяет одну durable пару incident/hold и restart
no-op. Оба файла входят в текущий execution/concurrency selection; runtime pass доказывают native
V1/V3, а не наличие этих assertions в исходниках.

## Readiness и Compose: дополнение P1.10 (D45)

`ApplicationReadiness` и `app:readiness` теперь обязательно включают восьмой check
`process_daemons` после прежних семи. Он делегирует `DaemonReadiness::ready()` без отдельного
health state: для каждой активной роли `relay`, `reconcile`, `feed` требуется хотя бы один
instance со свежим heartbeat (не старше 30 s по DB clock) без текущей ошибки. Свежий heartbeat
с сохраняющимся `last_error_code` остаётся degraded; missing/stale — down. Результат общего
check — прежний redacted формат `ready`/`unavailable`, без внутренних ошибок или exception text.
Projector пока исключён из общего readiness и не получает Compose service.

В `docker-compose.yml` и `docker-compose.prod.yml` объявлены `process-relay`,
`process-reconciler`, `process-feeder`. Каждый использует образ app, собственную команду
`php artisan process:daemon <relay|reconcile|feed>` и healthcheck той же роли с `--health`
(interval/timeout 10 s, start period 40 s, retries 3). Dev явно называет образ app
`${COMPOSE_PROJECT_NAME:-agelto}-app`, сохраняя development build и source mounts; prod использует
тот же immutable `agelto/app:${IMAGE_TAG}` и storage volume без source/vendor mounts.
Environment, file-backed secrets и сеть совпадают с app; зависимости — healthy app/PostgreSQL/Redis.
Restart — `unless-stopped`, SIGTERM и grace 60 s позволяют завершить текущую короткую транзакцию.
Общий `DaemonLoop` сохраняет пределы runtime 3600 s / memory 256 MB из `process.daemons` и
кооперативный stop; ledger/outbox восстанавливают незавершённую доставку после остановки.
Horizon сохраняет grace 4200 s, пока существуют legacy long jobs (минимум нового core — 370 s).

Это declarations и contract assertions, без запуска dev/prod services или активации реальных
application handlers. Isolated Compose/rig не добавляет фоновых daemons: тесты создают собственные
настоящие heartbeat rows, поэтому существующие двухпроцессные race suites сохраняют контроль
над своими workers. `ApplicationReadinessTest` проверяет все три роли missing/stale/degraded,
общий CLI, redaction и прежние семь checks; `T08DaemonReadinessTest` — health CLI каждой роли;
`T08SupplementalDaemonWiringTest` — разрешённые YAML aliases и image/project fixture values,
команды, образ, mounts, restart, health/grace и отсутствие projector в dev/prod declarations.
Эти tests входят в existing execution/full regression suites. Дополнение закрывает прежнее
ограничение ApplicationReadiness/Compose; invariant scan и projector здесь не реализуются.

## Контракт для потребителей и приёмка ядра (P1.12)

Схема и runtime код не менялись: P1.12 — приёмка state machine P1.6–P1.11 на настоящих PostgreSQL
connections/процессах и изолированном Redis rig, плюс этот контракт для owning пакетов P2/P3,
которые подключают реальные handlers. Production/application stages не активированы: `StageRegistry`
в production пуст, все проверки используют fixture handlers.

### State machine

| Из | В | Владелец перехода |
| --- | --- | --- |
| — | `ready` | `WorkStoreRepository::storeWork` (caller tx) либо successor в finalize |
| `ready`, `deferred` (due ≤ now) | `admitted` | `WorkFeeder` → `AdmissionRepository::admit`: group + members + `wake:<work>:<dg>` |
| `admitted` | `running` | `WorkStoreRepository::claim` из `WorkRunner` (только `Claimed` вызывает handler) |
| `admitted` | `admitted` (dg+1) | claim `CapacityWait`; reconciler `admitted` после истёкшей delivery |
| `admitted` | `blocked(paused\|handler_hold)` | reconciler `admitted` под committed pause/hold |
| `running` | `succeeded`, `ready` (partial), `deferred`, `blocked`, `failed_item` | `WorkFinalizer::finish` / `finishHandlerFault` |
| `running` | `ready`, `blocked`, `superseded` | reconciler `expired_running` (generation+1, attempt `interrupted`) |
| `blocked(paused\|handler_hold)` | `ready` | reconciler `blocked_release` (все scopes enabled, handler healthy) |
| `blocked(provider_result_unknown)` | `ready` / остаётся | reconciler `blocked_provider` по `provider_recovery_due_at` |
| `blocked(handler_fault_limit)` | `superseded` + новый work | только `RetryHandlerFaultedWork` |
| любое nonterminal | `cancelled` | `CancelWork` (generation+1) |
| любое nonterminal | `superseded` | изменившийся input/head под тем же prefix (admit/claim/finalize/recovery) |

Terminal (`succeeded`, `failed_item`, `cancelled`, `superseded`) не переписывается никем. Каждый
переход — одна `ProcessTransaction` с transition + `project:<event_id>` outbox. Group освобождается
целиком в той же tx только при settle, cancel, recovery, supersede и blocked-release; `CapacityWait` и
claim группу сохраняют (`dispatch_generation+1`, members остаются).

### API для owning пакетов

- **Регистрация** (composition root, до первой work): `StageRegistry::register(stage, version)`,
  `bindHandler(stage, StageHandler)`, `bindFinalization(stage, StagePayloadCodec,
  StageResultRepository)`; authority rows — `ProcessAuthoritySync::ensureBaseline()` и
  `registerHandler(stage, version)`. Stage вне `SchemaSupportedStages` отвергается; новая stage
  требует расширения CHECK baseline owning пакетом.
- **Создание работы**: `RunStoreRepository::storeRun`, `WorkStoreRepository::storeIntent`,
  `storeWork`, `linkRun` — внутри транзакции вызывающего, ID создаются до неё. `work_key` выводит
  `WorkIdentity` из immutable typed input; повтор того же input не создаёт вторую work.
- **Handler** (`Contracts/StageHandler::execute(WorkInput, ExecutionContext)`): возвращает ровно один
  из пяти `StageOutcome`; между частями вызывает `tick()`, перед внешним запросом — `canStart(full
  timeout)`, устойчивый прогресс — `checkpoint()`. Typed выходы `LeaseLost`, `SliceExhausted`,
  `DependencyUnavailable` не являются ошибками кода; любой другой Throwable расходует handler-fault
  budget. Handler не пишет ledger и не открывает DB transaction с внешним I/O.
- **Finalization repository** (`Contracts/StageResultRepository`): `storeProgress`/`storeResult`
  исполняются внутри locked finalize/checkpoint tx после guards: только SQL своих domain rows, без
  HTTP, sleep и lazy-load; `storeResult` возвращает ready successors как typed `WorkInput`.
- **Управление**: `ControlRepository::setMode` (pause/resume одной scope), `CancelWork`, `CancelRun`,
  `RetryHandlerFaultedWork`. RBAC/UI — P1.17.
- **Provider recovery**: до admission provider stage обязателен `RecoveryReconciler::bindProviderResolver`;
  `NoProviderOperations` честен только без provider stages.
- **Delivery**: потребитель никогда не dispatch-ит `ExecuteWorkJob` и не добавляет queue retry,
  release или middleware; wake публикует только relay из outbox.

### Порядок блокировок

Все пересекающиеся tx берут подмножество одного порядка и не возвращаются к раннему tier:
control scopes `FOR SHARE` (sorted `scope_key COLLATE "C"`) → handler-version row `FOR SHARE` →
lanes `FOR UPDATE` (sorted) → subjects `FOR UPDATE` (table key, PK) → work → provider rows →
derived/run links → journal/outbox.

| Операция | control | handler | lanes | subjects | work |
| --- | --- | --- | --- | --- | --- |
| admit | SHARE | SHARE | UPDATE | UPDATE | UPDATE SKIP LOCKED |
| claim | SHARE | SHARE | UPDATE | UPDATE | UPDATE |
| heartbeat | — | — | — | — | CAS |
| checkpoint | — | — | — | UPDATE | CAS |
| finalize / handler fault | SHARE | SHARE | UPDATE | UPDATE | UPDATE |
| recovery, CancelWork | SHARE | SHARE | UPDATE | UPDATE | UPDATE (SKIP LOCKED у reconciler) |
| setMode | ancestors SHARE, row UPDATE | — | — | — | — |
| fault detector | SHARE | UPDATE | — | — | — |
| outbox claim | — | — | — | — | own kind `SKIP LOCKED` |

Row, взятая `FOR SHARE`, в той же tx не обновляется. Изменившийся после pre-read состав lock set —
rollback и новый pre-read, а не поздний lock.

### Приёмка

| Сценарий | Проверки |
| --- | --- |
| T03 item budget, transport deferral, четвёртая delivery | `Execution/T03WorkBudgetDeliveryTest.php` через `WorkRunner`; `T09FinalizeFenceTest.php` |
| T06 cancel/new input fence | `T06WorkControlTest.php`, `Concurrency/T09FinalizeFenceConcurrencyTest.php` |
| T08 commit→publish и publish→mark kill, duplicate, Redis loss | `Concurrency/T31DaemonRedisLossAcceptanceTest.php`, `T08QueueEnvelopeTest.php`, `T08WorkRunnerDeliveryTest.php`, `Concurrency/T08OutboxClaimConcurrencyTest.php` |
| T09 heartbeat/recovery/finalize barriers, stale owner | `T09*Test.php`, `P19CorrectionRegressionTest.php`, `Concurrency/T09FinalizeFenceConcurrencyTest.php` |
| T24 pause против параллельных claim/finalize | `Concurrency/T24PauseClaimConcurrencyTest.php`, `Concurrency/T09FinalizeFenceConcurrencyTest.php` |
| T25 lease keeper/slice/DB loss | `T25ExecutionContextTest.php` |
| T31 два relay + два reconcile + FLUSHALL + гибель worker | `Concurrency/T31DaemonRedisLossAcceptanceTest.php`, `Concurrency/T31RecoveryReconcileConcurrencyTest.php` |
| EXR-02 single input → `handler_fault_limit`, journal rollback | `T03WorkBudgetDeliveryTest.php`, `T17HandlerFaultRecoveryTest.php`, `Concurrency/T09FinalizeFenceConcurrencyTest.php` |
| EXR-03 running → blocked_unknown → late receipt periodic scan | `T22BlockedProviderRecoveryTest.php`, `Concurrency/T31RecoveryReconcileConcurrencyTest.php` |

`T31DaemonRedisLossAcceptanceTest` исполняет настоящие сериализованные `ExecuteWorkJob` из Redis:
каждый relay/reconcile instance — отдельный PHP процесс; worker, погибший после claim, оставляет
durable running; lease, истёкший меньше чем на 2 s, recovery не трогает, на 3 s — восстанавливает
за один tick (граница lease + margin + tick). Delivery, зарезервированная до FLUSHALL, приходит
после redelivery и отвергается по dispatch generation. Revocation в finalize-тестах выполняют
настоящие `RecoveryReconciler`/`CancelWork`; fixture `FinalizeFixtures::revoke` больше не используется.
