---
audience: dev
type: reference
status: living
---

# Process core: ledger исполнения

PostgreSQL — authority исполнения (D2): work, intents, attempts, admission, journal и outbox
живут в `prc_*` таблицах Process ledger; Redis/Horizon только доставляют. Точный DDL,
staging и ownership каждой колонки — [Process schema](process-schema.md).

Обслуживание проекций и управление run описаны в [runbook process controls](runbooks/process-controls.md).

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
`ProjectorDaemon` (P1.15, `process:daemon project`) claim-ит только `projection`, применяет событие
всем registered reducers (`ProjectionRegistry`) и подтверждает сообщение после receipts всех получателей;
подробности — `docs/dev/runbooks/process-controls.md`.

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
health state: для каждой активной роли `relay`, `reconcile`, `feed` (и `project` с P1.15) требуется хотя бы один
instance со свежим heartbeat (не старше 30 s по DB clock) без текущей ошибки. Свежий heartbeat
с сохраняющимся `last_error_code` остаётся degraded; missing/stale — down. Результат общего
check — прежний redacted формат `ready`/`unavailable`, без внутренних ошибок или exception text.
С P1.15 четвёртая роль `project` входит в readiness наравне с остальными и получает Compose service
`process-projector` (команда `process:daemon project`, healthcheck `--health`).

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

## HTTP transport: construction P2.2

`Network/OutboundExecutor` owns the bounded physical-attempt loop. It takes an explicit
`OutboundAttemptGate`, `UrlPolicy`, operation `TransferBudget` and runtime `HttpRequestPayload`.
There is no production gate binding or legacy caller cutover here: P2.3 owns scheduled permits,
P2.4 health/probes, P2.6 switches every caller after P2.5. Existing retry/health consumers remain
with their current owner until that cutover. Provider ambiguous writes allow one physical send.

The gate must reserve/wait and commit a fresh fenced authorization for every attempt/hop,
respect the exact route profile and distinct-route bound, and record/release each outcome.
Cross-origin redirects resolve the catalog target for the new origin; origin quota is never
reused from the previous target. Transport errors prefer an eligible untried route immediately;
HTTP 503 repeats the current route, 407 holds credentials, and 429 sets origin cooldown without
rotation. Retry-After up to 24 hours is retained; longer values require policy review.

`HttpTransport` uses a private Laravel PendingRequest with an explicit synchronous cURL handler.
Automatic retries, redirects, cookies and environment proxy fallback are disabled. The handler
releases cURL even when an idle callback throws. The disk-backed sink limits decompressed bytes
before writing; independent operation budgets include transferred and decompressed bytes across
retries/hops. Full profile timeout plus finalization reserve must fit; it is never shortened.
Low-speed means average transfer below 1 B/s for the configured interval. Cancellation/lease loss,
slice exhaustion and dependency failure preserve typed outcomes and partial byte evidence.

URL policy permits exact canonical scheme/IDNA host/port origins, checks every DNS answer, and
pins the connection (including CONNECT) to a validated IP while retaining the original TLS name.
Canonical wire host prevents trailing-dot/case aliases from bypassing the pin. DNS runs in a
cooperatively polled PHP child under the existing GNU `timeout` utility, bounded by connect timeout;
there is no alarm/fork heartbeat or unbounded PHP DNS call. Missing resolver support fails closed.
TLS trust remains solely in `SourceTlsTrust`; insecure fallback is unavailable.

Text is decoded after cURL Content-Encoding decoding: BOM, supported header charset, actual HTML
meta in the first 4 KiB, endpoint default. Invalid source bytes, unsupported encodings and lossy
round trips fail explicitly; normalized UTF-8 expansion has its own ceiling. Binary responses
bypass text decoding and return a staged local `BinaryBodyArtifact`; intermediate artifacts and
failed spools are removed. The document owner will supply durable storage accounting in P3.2.
`wireBytes` is cURL's encoded transfer-body counter, excluding headers/TLS; this does not promise
a retained wire artifact. Artifact hashes describe transport-decompressed bytes.

Transport-only assertions live in `T03HttpTransportTest`, `T04HttpPolicyTest`, and
`T47ResponseDecoderTest` (groups p22 plus t03/t04/t05/t21/t25/t28/t47). The server owns fault
sequences and receipts; the fixture gate explicitly does not prove future DB permits or health.
Real loopback HTTP/CONNECT/TLS, stall/headers-late/45s progress, cancellation, full timeout fit,
strict encoding, gzip/byte limits, memory ceiling and cleanup are exercised in the isolated rig.
Local CA/key/ports are generated per test under disposable storage; no hash-pinned fixtures change.
Acceptance V1/V2 remains with the coordinator.


## Scheduled network permits (P2.3)

`NetworkPermitRepository` now owns registration, FIFO reserve and a separate fenced `claimSend`.
It uses the P2.1 baseline tables without DDL changes. Legacy callers, writers and DI bindings
remain at their existing owner until the P2.6 cutover. P2.4 supplies `OutboundRouteSelector`
and health/probe reduction; `ScheduledAttemptGate` composes its explicit route selection with
this permit protocol and the existing single-send transport. No SDK retry is introduced.

A physical UUID keeps one ticket and its sequence across polling. An expired ticket cannot be
revived; another execution/request identity registers a new ticket. At most 64 live pending
demands share an origin, with one pending demand per owner. Renewal does not reset the original
20-second capacity-wait bound. Probes join this same FIFO and add the probe concurrency scope.
Unknown health requires an owned probe; no ordinary readiness bypass exists.

Reserve and claim lock Process controls, handler authority, all current capacity/subjects/work
through `AuthorityRepository`; standalone operations use their stored inherited controls and
live operation token. The network subset locks profile, route, route health, route-target health,
target, target health, then all budgets in scope-key order. Missing authority fails closed.
Claim revalidates live ownership after network locks, exact membership, input/ancestor versions,
route membership, policy/epoch evidence, target retirement, `not_before`, `send_tat`, and full
profile timeout plus finalization fit. A changed policy releases the reservation for re-evaluation.
A duplicate claim grants no transport capability. Request intent and sent authorization commit
atomically before wire; completion records facts/releases capacity without business publication.

Late claim extends permit expiry to at least authorization + FULL timeout + 30-second cleanup.
Already expired, foreign or revoked permits never extend. Reserved and sent permits both occupy
inflight slots until completion or their covered expiry. Cleanup and crashes do not refund TAT.
429 monotonically advances origin `not_before` and TAT, affecting every previously reserved permit.
Status cap uses configured origin capacity, including reserved future permits and each physical
retry/redirect exactly once; authorization remains counted for the rolling hour after release.
Quota deferral reports the actual window/expiry that restores room, including after a rate change.

`claimSendLocked` is the future joint financial/network seam: its caller must hold and validate
the complete core prefix plus financial rows in the SAME transaction and roll back both claims
on any denial. Only its committed successful claim may create a single-use prepared transport;
no separate financial gate or provider retry is activated here.

Current proof files (groups `p23`, mapped to `core-test-network`) are `T23GcraConcurrencyTest`,
`T34BoundedFifoDemandTest`, `T35ClaimSendFenceTest`, `T36StatusCapacityQuotaTest`, and
`T37NetworkDemandRecoveryTest`. They use real PostgreSQL locks and local HTTP receipts, including
8 separate backend PIDs, duplicate claim, reserve→pause/hold/retire/429, all-or-none scope grant,
64-ticket ordering, quota accounting, stale head/bridge cleanup, and late coverage while HTTP
is still active. Fixture rate 600/min accelerates timing; defaults stay 15/min. A DB fixture models
33 seconds of pre-send scheduler delay; the active HTTP/competing-capacity assertion is real.
These tests establish bounded FIFO grants within a live slice; cross-slice admission fairness
remains the separate T11 contract. They make no production throughput or p99 latency promise.
Coordinator runs acceptance V1/V2 on the delivered candidate; targeted executor logs and hashes
are under `storage/app/testing/process-core/reports/p23-executor-20261029/`.

## Route health and owned probes (P2.4)

The new construction seam is `ProxyResolver::outboundRoutes(RoutePolicy, ?ProbeClaim)`.
`RoutePolicy` contains exact runtime bindings and the profile identity, or one explicit direct
binding. There is no fallback to another profile or to direct. Selection checks administrative
eligibility and all three health scopes; the existing scheduled gate rechecks them under locks.
Untried eligible routes take precedence after a retryable transport failure. Once the distinct
route budget is exhausted, the current eligible route may repeat. Origin 429 never rotates;
502/503/504 keep the current route. Least-recent actual authorization orders fresh selections.
A disabled route stays disabled after success.

`RequestClassifier` consumes typed transport facts. Proven proxy connect failures affect route
global health; 407 holds its credential version until replacement and permits an allowed alternate
route. Origin TLS, cancellation, byte/decode limits, storage and database failures do not become
proxy failures. A parser failure after a received response cannot rewrite its transport success.
`HttpRouteBinding` and selection pin the credential version through reserve/send. Send-time route
kind, independence group, credential version and probe token are validated by `HealthAuthorization`
and stored in the request's versioned metadata. Late outcomes use this immutable metadata.

`HealthRepository` commits request completion, observation, three dedup applications, health
reductions and permit release in one transaction. It locks the same route/health/target subset as
network authorization. Duplicate physical outcomes apply once. An epoch mismatch or expired/foreign
probe token retains a stale observation and leaves all current counters unchanged. No business work
state is published by health application; the existing legacy writers remain active only for their
legacy consumers until P2.6. New construction fixtures do not activate those consumers.

Defaults in `outbound.health` are boot-validated: global five proven consecutive failures,
1800s ×2 capped at21600s; route-target five consecutive or >=60% of >=10 eligible outcomes in
rolling600s, 300s ×2 capped at3600s. Success resets its own consecutive series; openings decay by
one only after600s of stability, with a new stability interval before another decay. Ignored
outcomes do not enter the denominator. Target availability is separate: unknown independence
leaves suspect and permits another route. Target opens only on fresh failures of at least two
explicit distinct independence groups within300s, or explicit adapter-confirmed maintenance
passed to `HealthRepository::record`; cooldown is at least900s and preserves longer Retry-After.

A typed bounded probe operation supplies its unique token to `ProbeScheduler::claim`. It checks
the complete route-global/route-target/target set before changing any row. Unknown, due-open and
expired-probe scopes become half-open together with a new epoch and exact token scope set.
Ordinary sends remain denied until readiness is proven; due probes return target_cooldown rather
than no_route. A failed second scope leaves the first untouched. The probe lease covers the full
request plus inline wait and cleanup reserve. The claimed operation then uses the same scheduled
FIFO/GCRA and origin/probe capacity budgets through `ScheduledAttemptGate`. The send fence checks
the exact token footprint again. Expired tokens cannot reclaim themselves; the next operation
gets a fresh token. Cancellation releases the complete probe set without failure penalties.

Scoped proof files (group `p24`, existing `core-test-network` mapping): `T04RouteHealthTest`,
`T05ProbeOwnershipTest`, `T29HealthReducerTest`. They inspect real PostgreSQL facts, actual local
HTTP/CONNECT receipts,20 independent backend contenders for one actual probe, duplicate outcome
races, stale owner fencing, observation-insert rollback, deterministic thresholds and600s decay.
Synthetic typed outcomes test reducer policy; they do not substitute for the real wire/concurrency
cases. The existing T03 credential test now verifies auth failure on A followed by allowed B,
with no retry of A's credential. No schema change or install/reset is required by this slice.
Executor logs and candidate hashes: `storage/app/testing/process-core/reports/p24-executor-20261029/`.
Full acceptance and runtime/old-writer cutover remain coordinator/P2.6 responsibilities.


### Atomic quota receipts (P25-01 / P25-02)

`OutboundAttemptGate::record` returns the durable quota decision. `HealthRepository::record`
uses the DB clock to parse Retry-After and locks health/target authorities, then the demand-pinned canonical
origin budget, then the request/permit. The same short transaction updates origin `not_before`
and TAT, receipt status/outcome, observation/dedup and permit release. Duplicate recording returns
the original RetryAt without extending cooldown. Reserved permits and new requests recheck the
origin fence before sending. Network I/O remains outside this transaction.

`HttpResponseHead` captures received status/headers in the cURL headers callback before entity
transfer, decompression, byte limits, text decoding or storage can fail. CONNECT handshake headers
are suppressed here; their status remains proxy-phase evidence in cURL stats. A malformed 429 still
returns the durable rate-limit decision; request outcome and observation retain the separate
body failure. Observation details store HTTP status, bounded printable Retry-After plus its hash,
and the quota decision/RetryAt. Quota responses do not increase proxy failure counters.

P25-01 regression: `T35ClaimSendFenceTest` kills a real PHP/PostgreSQL worker immediately before
receipt commit and immediately after `record` returns. It checks rollback/all-or-none state,
idempotent retry, unchanged RetryAt and zero additional local-server receipts from reserved or
fresh callers. Its pre-commit replay uses a test-owned copy of the received response; a production
crash before factual commit can still leave an unknown request.

P25-02 regressions in `T04HttpPolicyTest` and `T35ClaimSendFenceTest` combine HTTP 429/Retry-After
with invalid UTF-8, invalid gzip, unsupported encoding, gzip expansion and plain entity overflow.
They assert both HTTP/body facts and the durable origin fence. The tests retain sanitized SQL and
local-server evidence under test storage `app/testing/process-core/quota-evidence/<process>/`.
Legacy caller/health-writer activation remains owned by P2.6. These assertions do not close the
independent P2.5 review or replace coordinator acceptance.


## Historical P2.6 partial candidate (2026-10-29, request 6)

The following records the incomplete request-6 snapshot. Current code and acceptance are described
in the current network cutover section below; this historical status does not describe the current tree.

This candidate has **not completed the all-caller network cutover**. The legacy transport loops,
proxy/source writers, request budget and binding schema still require their coordinated replacement.
Do not activate this partial tree or start P2.7 on its narrow test results. V1–V4 remain conductor-owned.

The retained SourceCircuits UI displays historical rows only: canonical Process controls own pause/resume
and audit; direct legacy edit/save is denied. DetailDispatcher now reads canonical acquisition controls
and target cooldown/retirement eligibility before applying its batch limit. Excluded procurements remain
due; a historical circuit row does not exclude another platform. This projection never authorizes HTTP.
The locked owner/permit gate remains the authority for an actual send.

The existing ReconcileDaemon now registers bounded expired outbound-owner cleanup. It invalidates
reserved permits through LegacyBridge without queueing a business retry or erasing request evidence.
Probe dispatch and all remaining caller wiring are unfinished.

Provider catalogs use an exclusive provider identity rather than a fabricated procurement platform.
Narrow PostgreSQL tests cover shared origin debt, idempotent isolated sync, conflicting defaults,
negative ownership/uniqueness constraints, and canonical provider pause/resume with audit. Malformed
catalog profiles produce a configuration hold before operations or requests are created. These checks
do not yet prove an actual Llm caller through the complete transport bridge.

ConnectionException and typed outbound dependency/control exits no longer consume DocumentDownloader's
item budget. The affected downloader file passes 20 cases/97 assertions, including preservation at the
last remaining item attempt. Its existing physical retry/health path still awaits the common executor.

Current commands, red history and candidate hashes are in the five assigned
`.swissknifeman/orch-artifacts/p26-cutover-20261029/` evidence files. No acceptance, clean-install,
full regression, deployment or paid application execution is claimed.

## Current network cutover: P2.6 and P2.7 (2026-10-30)

P2.6 routes every existing CSV/card, document, OKPD2, document-UID and Llm SDK/cache/file
caller through one physical executor with an owned operation, PostgreSQL permits, exact route
profile membership and `SourceTlsTrust`. Legacy proxy/source health writers, the Redis request
budget and their obsolete commands/schedules have been removed. Surviving domain schedulers
remain in place until their own cutovers; historical source circuits are read-only evidence.

D49 binds a versioned adapter contract to full capability validation. One owner covers all tabs
and pure parsers; HTTP success alone cannot validate the adapter. Distinct-input corroboration,
local input budgets, stale-result fencing, audited reset and gradual capacity recovery are proved
separately from transport health. A terminal HTTP error retains bounded decoded evidence without
restoring a second retry loop. Existing waiting runs preserve their absolute retry time and cursor;
failed queue publication releases the exact SQL and unique claims.

P2.7 acceptance evidence records clean installs, real local HTTP/proxy/TLS fault assertions,
PostgreSQL concurrency, maximum-level static analysis and full no-TIA regression. The frozen
2,014 original executed scenarios retain their exact identities. Native Planner owns the precise
check results and candidate references for `PLAN1.P2.6` and `PLAN1.P2.7`; the five evidence carriers
are under `.swissknifeman/orch-artifacts/p26-cutover-20261029/`. Formal independent review remains
owned by P2.8. This records repository acceptance; production deployment is a separate operation.

Diagnostic and operator recovery commands are documented in
[the process rig runbook](runbooks/process-test-rig.md#p26-диагностика-и-восстановление-после-переключения-сети).

## P2.14: canonical identity и extension persistence

Primary identity — точный `(platform_id, source_identity_type, source_identity_value)`.
RegistryDefinition задаёт type; IdentityResolver проверяет platform/registry по trusted catalog.
Значение opaque: ведущие нули, регистр, пробелы и Unicode representation сохраняются.
Clean baseline использует text COLLATE "C", UNIQUE tuple и composite registry/platform FK.
Общие DTO и procurement больше не содержат registry_number/ikz/etp_number/registry_guid;
для отображения служит source_identity_value, остальные identifiers принадлежат EisDetailExtension.

SnapshotFinalizer::lockIdentities вызывается в caller transaction до work lock: сначала
все deduplicated advisory keys в signed64 порядке, затем existing subject rows по id.
После owner fence persistLocked использует тот же transaction-bound handle и не начинает
транзакцию или HTTP. INSERT ON CONFLICT по exact identity constraint и locked reread
не требуют повторного fetch; 23505 не добавлен в transient retry policy.
Legacy block ingestor не поглощает persistence exceptions: весь block откатывается,
а raw artifact подготавливается до publication transaction.
Legacy ingest entrypoint сохраняется до producer cutover P2.18. Новый stage handler здесь
не активируется; P2.15/P2.16 подключают listing/detail/status publication к этим primitives.

EisExtensionPersister — единственный application writer prc_eis_details. Registry выбирает
точный typed writer; ошибка откатывает canonical revision/events и cursor вместе с extension.
CompleteEmpty common-info может очистить прежние common fields; listing metadata не
обнуляется отсутствующими полями. Старые detail consumers используют тот же writer.
EIS UI fields загружаются через registered UI extension, без EIS imports в shared resource.

T41 проверяет opaque values/collation, platform и registry separation, FK rejection,
forced advisory hash collision, ON CONFLICT и два PostgreSQL contenders с одним physical
fixture HTTP на block и двумя domain commits. T42 проверяет rollback revision/events/cursor,
caller transaction и negative writer ownership fixtures. core-test-acquisition теперь запускает
эти текущие assertions; полная группа acquisition принимается в P2.19.
Schema inventory фиксирует фактические три изменённые таблицы; marker current_item остаётся
последним полностью активированным carrier stage, без ранней активации future carriers.
Двукратный isolated install и полную regression запускает conductor (V3/V4).

## P2.15: listing implementation и адресные proofs

`ListingHandler`, `ListingStageCodec`, `ListingFinalizer` и `ListingStoreRepository`
реализуют bounded page work на существующих crawl subjects. Production binding и
producer cutover остаются P2.18; fixture registry связывает настоящий handler/finalizer
с Process core уже сейчас. `StagePublicationRepository` добавляет typed publication
locks до work lock и discovery closure после создания successors в той же transaction.

| Assertions | Файл | Group / owning target |
|---|---|---|
| T07: real EIS parser, partial rejection, full codec/publication | `T07ListingHandlerTest.php` | t07,p23 / core-test-acquisition |
| T27: inclusive/decimal, deletion-before-offset, content stability, outage, split/debt, closing reconciliation, interrupted passes, rollback | `T27ListingCoverageTest.php` | t27,p23 / core-test-acquisition |
| T09/T27: two actual PG owners, one accepted publication and successor graph | `T27ListingConcurrencyTest.php` | t09,t27,p23 / core-test-acquisition |

Файлы находятся в `tests/Feature/ProcessCore/Acquisition/`. Адресные логи и hashes
текущего candidate: `storage/app/testing/process-core/reports/p215-executor/`.
Это доказательства P2.15, не приёмка всей группы P2.14–P2.19. DDL не меняется;
watermarks rebuildable из durable page receipts. Исторический объём coverage projection
растёт с числом runs: bounded allocation не является обещанием постоянной стоимости
полного rebuild. Детальный operational rollout и cadence принадлежат producer cutover.

## P2.19: приёмка полного acquisition

P2.19 сверяет producer cutover P2.18 с contracts `observability#s04` и `execution#s10`
на настоящих `AcquisitionScheduler`, `ListingFinalizer` и `DetailFinalizer`.
Сценарии находятся в `tests/Feature/ProcessCore/Acquisition/T18RunLifecycleProducerTest.php`
(groups t17,t18,t02,p23; target `core-test-acquisition`):

| Сценарий | Что доказывает |
|---|---|
| Две стабильные passes, detail с document reference | discovery `complete`; pending download intent без work не удерживает run (`completed`, observes=0) |
| Superseded successor и очередь intents | устаревшая detail work заменяется successor на текущем head в той же tx; required links всех runs переходят на successor; pending intent исполняется после active; оба runs `completed` без superseded/failed required |
| failed_item и cancel | terminal work освобождает intent, следующий pending активируется; после очереди `RefreshRepository` снова выделяет detail-цикл |
| Manual retry failed_item detail | manual retry завершает active intent и закрывает run как на неизменном head, так и после supersede re-pin на новом head |
| T02 | пауза `acquisition:platform:<A>` не блокирует claim, listing и закрытие discovery площадки B |
| EXR-01 | sidebar с реальным acquisition run без `prc_source_circuits` и маршрутов `source-circuits` |

### Найденный дефект и исправление

На кандидате P2.18 повторный listing той же закупки с изменённым содержимым увеличивал
`head_generation` и создавал pending detail intent вместе с work. Прежняя work уходила в
`superseded` без successor, work pending intent — тоже (admission считала неактивный intent
устаревшим). Intents оставались `active`/`pending` навсегда, `RefreshRepository::allocate`
больше не выделял detail-циклы, runs теряли required detail.

Исправление (Process core, без изменения DDL):

- `WorkStoreRepository::supersedeLocked` для `DetailInput`, устаревшей из-за нового subject
  head, в той же transaction создаёт successor под актуальным `intent_id` work-строки (с fallback
  на `DetailInput::$intentId`) и `expected_head_generation` текущего head (derived stable IDs),
  обновляет pin intent и переносит links через `carryRunLinks`: required → successor, прежняя
  связь → observes; cancelled run required не получает. Если intent уже terminal, successor —
  существующая work того же intent на текущем head.
- `DetailFinalizer`, `StatusFinalizer`, `DetailSnapshotRepository` и `AcquisitionProvenance`
  согласованы с manual retry (`prc_process_work.intent_id` как authority active intent при
  сохранении immutable `WorkInput`), а неиспользуемые legacy-носители `RunStatus`, `FetchResult`
  и `CrawlerRuntimeOptions` удалены.
- `AdmissionRepository` не выбирает и не supersede-ит work, чей intent `pending`
  (`LockedPrefix::intentPending`): она ждёт очереди в `ready`.
- `IntentRepository` ведёт очередь (stage, subject): terminal work без successor
  (`failed_item` в finalize, `CancelWork`) завершает intent; успех, после которого publication
  перевела intent в terminal, только продвигает очередь. Следующий pending без
  `pending_reason` активируется с пересчитанным `expected_head_generation`.
  Document intents с `pending_reason=handler_not_activated` не активируются.
- Manual retry failed_item создаёт новый intent `pending`, если у subject уже есть active intent
  (partial unique index `prc_operation_intents_one_active_unique`).

## P3.1 — immutable document storage (staged)

`DocumentVersionRepository` publishes immutable observed revisions using the document's
`head_generation/current_version_id` CAS. Re-observing current bytes is idempotent; A→B→A records
three revisions backed by two domain-scoped SHA-256 blobs. Original and representation locations
always include the recorded disk. `DocumentRepresentationRepository` keys output by version,
preparation recipe, kind and range, and persists typed source coverage with explicit missing ranges.
These repositories do not read crawler flags or source health and do not activate document jobs.
The owning WorkFinalizer must invoke their locked methods under its complete capacity/subject/work
prefix; a bare successful storage write never changes WorkState.

`StorageCapacityRepository` reserves the hard byte ceiling before I/O. Upload and local spool use
separate storage domains. Active reservations in reserved/writing/staged/**cleaning** remain
charged. A successful immutable read-back permits the caller's transaction to transfer one reserve
to durable accounting. A deduplicated upload leaves its distinct staging key charged until verified
cleanup; it never deletes the winning object's key. Cancellation, lease expiry, exceptions and slot
release do not prove deletion. A writer killed during object put remains `writing`; reconciliation
must prove writer/process termination before cleanup (owning download/prepare integration).

GC locks the same storage authority as reference acquisition, marks an unreferenced blob with a
monotonic deletion generation, deletes outside the DB transaction, verifies absence and finalizes
accounting with that generation. A marked blob rejects new references until verified cleanup;
current and historical version/representation references protect it. DB reference triggers also
reject a direct INSERT racing with deletion. Failed cleanup retains both the mark and capacity.

P3.5 completes the canonical cutover: every version/representation has an explicit nonempty disk
and complete blob metadata. Version hashes are PostgreSQL bytea(32); Eloquent exposes validated hex
at its boundary. The legacy NULL-tuple exceptions and partial uniqueness indexes are removed.
Versions are revisions (A→B→A is three observations/two blobs), representations use immutable
(version, recipe, kind, range) identities. No writer may update their bytes or disk in place.

Surviving manual/fixture/dataset/archive and format/bench writers use StoredDocumentWriter:
object hash/size verification outside transactions, early storage authority locks, blob reference/
deletion gate and canonical publication. A catalogue object deleted after verification cannot be
resurrected from stale metadata. Factories create explicit synthetic canonical metadata only; they
are not evidence that physical bytes exist. Recorded version/representation disks no longer fall
back from NULL. The generic disk-copy algorithm remains tested on mutable fixture carriers;
document-version transfer is rejected before I/O. Typed runtime download/prepare stays owned by
WorkFinalizer. Manual fixture entry preserves working-copy identity, active-run/screening guards,
supersede rules and requested-by provenance, producing durable preparation work rather than an
ordinary queue job. Real provider stages remain unregistered at this boundary.

P3.1 evidence comes from `make core-test-documents`: current-head CAS on separate PostgreSQL
processes, A→B→A, GC/reference interleavings, storage capacity contention, uncertain uploads,
dedup/cleanup, archive lineage and retained archive/blob safety tests. `core-test-install` still
requires two complete isolated installs and exact schema/seed/catalog equality. Future OCR,
independent feeder, fairness and real AI assertions belong to their owning points.

### P3.2: download и revalidation (до consumer cutover P3.5)

`Document/DownloadHandler` получает typed `DocumentFetchPlan` через узкий
`DocumentAccessResolver` и вызывает общий `PlatformOutbound`/`OutboundExecutor`.
Сеть владеет physical retries, routes, permit/send fencing, timeout и bounded binary sink;
handler не добавляет Laravel retry. Профиль download имеет total ≤240 s и помещается
в существующий slice300/job320/Horizon340/retry_after390/lease420. Signed-reference hook
может обновить ссылку один раз; refresh сохраняет resource policy и общий finite send ceiling.
`CatalogDocumentAccess` читает HTTP descriptor и sync catalog; при требовании source-specific
refresh без адаптера возвращает явный policy hold, не угадывает endpoint.

До HTTP `DownloadStorageRepository` резервирует durable ceiling и консервативный temporary
ceiling 3×maxBytes под control→handler→lane→storage→document→work prefix. Частичный reserve
откатывается целиком. Transport spool/artifact и upload spool покрыты temporary charge.
Успешный original проходит size/hash read-back, MIME/signature inspection и остаётся staged
до `WorkFinalizer`. HTML/challenge, checksum/size mismatch и truncated transfer не создают
version. Storage errors не расходуют item budget. Обычный отказ подтверждённо очищает staging;
crash/uncertain writer сохраняет charge до reconciliation/verified cleanup, а не освобождает
capacity по одному expiry. `BlobStore` вызывает cooperative tick при copy/verification.

`DownloadFinalizer` — DB-only stage repository: publication locks берут storage до subjects/work;
canonical owner/input/head guards предшествуют blob acquisition и revision CAS. Version/blob,
intent terminal, следующий DB-clock revalidation due, work/attempt, journal/outbox и release
group коммитятся вместе. Same bytes сохраняет текущую revision; A→B→A даёт три revisions и два
blobs. ETag/Last-Modified сохраняются как provenance; каждый due cycle делает полный content
recheck, поэтому неверные validators не скрывают замену bytes. `DocumentRevalidator::allocate`
сериализует due allocation на document head и создаёт один pending intent/cycle; initial intent
по-прежнему создаётся atomically вместе с acquisition reference.

`documents.download` содержит validated storage/size/revalidation policy. Домены capacity
провизионируются явно; boot не создаёт DB rows. Новые stage codec/handler/finalizer готовы к
assembly, но runtime registration, pending intent activation, prepare successor и удаление old
jobs/dispatcher принадлежат P3.3/P3.5. Existing consumers остаются на прежнем пути до cutover.
Никакой real AI stage здесь не активирован.

Assertions P3.2 находятся в `T03DownloadRevalidationTest` (groups t03/t10/t12/p32): реальные
PostgreSQL + cURL HTTP/proxy, bounded failures, no direct fallback, corrupt/oversized/truncated
bytes, signed refresh, source outage после bytes, stale head/takeover/expiry, journal rollback,
A→B→A и два процесса due allocator. Prepare/analysis independence проверяется следующими
owning пунктами; source-off DB publication не заявляется полной downstream приёмкой T10.

## P3.3: page-batched prepare (runtime cutover P3.5)

`Document/PrepareHandler` и `PrepareFinalizer` готовят immutable PDF bytes без source/crawler
health. Один Prepare WorkItem выполняет один whole-page batch за delivery и сохраняет
`PrepareOcrCheckpoint` v1 через `ExecutionContext::checkpoint`. Checkpoint содержит source/recipe
hashes, original page count, pending half-open ranges с retry counts, verified artifact refs и
per-page statuses. Текст не сериализуется в work. Initial batch — 5 pages; target 150 s,
adaptive next batch 1–20. Chain ceiling 200 s включает copy/probes/tools/persistence; reserve
не менее 30 s внутри prepare slice 240 s. Внутри chain выделены setup/probes 30 s,
tool batch 150 s и persistence 20 s. Не помещающийся после setup полный batch даёт
blocked(converter_policy_incompatible), без page failures и пустого Partial. Resource/profile policy находится в `documents.prepare`.

`PrepareStageCodec` отвергает unknown fields/version, coercion и пересекающееся/неполное
accounting страниц. Multi-page tool failure делится left-first; singleton допускает ровно две
подтверждённые ошибки. Control/dependency/storage exits не расходуют page/item failure budget.
После каждого batch source/recipe/current-head/lease guards защищают checkpoint. Finalizer берёт
storage capacity до document/version/work и публикует только complete required coverage. Общий
resolver расширен preloaded document ancestor для prepare; immutable version mapping проверяется
снова под subject locks. Это composition seam, не новый state owner.

`Prepare/PageBatchOcr` использует qpdf, OCRmyPDF `--skip-text --jobs 1`, затем page-preserving
pdftotext из выходного PDF. Native text сохраняется; sidecar остаётся evidence внутри временной
цепочки и не используется как полная копия. Manifest различает native_text, ocr_text,
proved_blank (только с evidence), unrecognized, failed, not_attempted. Лимит 300 не меняет
original page count: 301-я страница остаётся missing, source incomplete. Threshold 10% — только
operational quality label. Пустой текст, timeout skip с exit 0 и отсутствующая required page
дают blocked(document_quality), никогда complete/verified. Исходный PDF остаётся доступен.
Tool identities/options входят в recipe; drift требует нового recipe, а не смены tools на resume.

`Prepare/BoundedToolProcess` запускает независимый Linux supervisor (Python stdlib) без shell.
Он ограничивает wall/CPU/address-space/file size, следит за совокупным workspace size, останавливает
TERM→KILL process group и reaps adopted descendants через subreaper. PHP poll 100 ms вызывает
cooperative tick. При SIGKILL PHP wrapper продолжает hard deadline и оставляет stop receipt.
`PrepareWorkspace` удерживает quota, пока активен tool marker или прежний PHP owner; PID/start
identity и explicit PHP-finished marker позволяют повторить orphan cleanup. Stop receipt,
подтверждённое удаление каталога и DB cleanup acknowledgement предшествуют освобождению bytes.
Workspace size polling — дополнительный контроль, не filesystem quota; production sandbox/egress
activation этим срезом не подтверждается. Acceptance работает в internal Docker network.

Global handler registration, feeder/jobs switch, ordinary document bridge removal и archive/native
strategy consumers остаются границей P3.5. Legacy strategies/guards/tests сохранены до этого
cutover; новый PDF handler не вызывает их status writers. Другие форматы возвращают явный hold
до composition через format registry. Реальный AI не активирован. Schema не изменялась.

T26 fixtures: `Documents/prepare-v1.json`, mixed native/scanned/empty PDF; deterministic corpus
проверяет ≥3 deliveries, restart/no-repeat, split/singleton exhaustion, truncation301, recipe
mismatch, stale publication и tmp accounting. Real tools проверяют sidecar omission и successful
exit с tesseract timeout; реальные процессы — stall, lease loss и PHP SIGKILL/orphan reap.
Archive safety corpus по-прежнему выполняется `make core-test-documents`.

## P3.5 — independent document runtime

`DocumentFeeder` runs as a bounded producer before admission in `process:daemon feed`. It allocates
due revalidation cycles and activates pending download intents under the document head lock. The
common `WorkFeeder`, admission groups, outbox relay, `ExecuteWorkJob` and `RecoveryReconciler` own
all execution, retry, W/C and recovery. Acquisition runs do not own document revalidation.

Explicit provisioning is `php artisan process:sync-documents` after migrations and before workers.
It validates storage capacities and installs download/prepare handler authorities; routine boot is
pure composition. `platform:sync-catalog` also registers document handler versions.

Download publication atomically creates the immutable revision and its independent prepare work
with the current head generation. Missing prepare work is recovered by the producer. Ready,
blocked, failed and terminal work identities are never reset by polling. Document status is only
a read projection. Network failures do not consume the document item budget.

After verified bytes, acquisition pause and source retirement do not stop prepare or fixture
analysis. Screening/extraction share the analysis parent quota; T11 exercises the current screening
fixture with manual and two platform cohorts at W10/W20. Extraction runtime/schema activation
remains P3.8–P3.13; no extraction fixture is claimed against a nonexistent analysis-window schema.
Successful prepare creates a pending screening intent with `handler_not_activated`. Real paid AI
is not activated here. Existing legacy AI algorithms are retained solely for deterministic
fake-provider regression until P3.13; their old job/manual entrypoints reject non-testing runtime.
The fixture UI fails explicitly before provisioning or mutation.

PDF page preparation is the currently activated typed prepare path. Other format strategies retain
their deterministic safety corpus through a test-only strategy probe; they are not presented as
activated typed runtime. Unsupported formats remain explicitly blocked(policy_review). This is an
existing P3.3 boundary, not successful conversion or complete-source publication.

Document storage cleanup is a bounded category of the common reconcile daemon. It waits for
revocation/terminal work and verified tool/workspace stop; expiry alone cannot delete an artifact
of an unrecovered owner. Checkpoint-referenced batches remain protected, uncertain writers retain
their charge. Duplicate downloads verify or restore identical canonical bytes before the DB-only
reference gate. No network/object I/O runs in a DB transaction.

P3.5 evidence covers bounded concurrency, not the 1000-document/30-minute T16 load proof, which
belongs to P3.18/P3.19. D18 scenario dispositions are recorded in
`tests/Fixtures/ProcessCore/scenario-transfers.json` and the document cutover runbook.

## P3.6 — document acceptance boundary

The reference→recorded HTTP download→registered PDF preparation test now passes the hash of its
actual immutable representation into an explicit test-only screening handler and transactional PG
fixture sink. Duplicate delivery accepts one fixture result. Two platform cohorts and manual use
the same prepared-byte consumer with paused acquisition and retired source targets. Exact source
page coverage and run/intent/version links are asserted; head replacement prevents publication,
and mandatory journal failure rolls back the fixture result and work finalization together.

The temporary sink is test evidence for WorkFinalizer guards, not production estimate publication.
Screening intents remain pending; no provider is activated. Historical AI recovery assertions from
P3.5 still run in their frozen test-only harness and do not establish production AI coverage.
PDF is the activated typed prepare path; other format strategies remain a safety corpus with an
explicit runtime hold. Bounded W10/W20 proofs do not establish future T16 load or model accuracy.

See [document acceptance](runbooks/document-acceptance-p36.md) for the invariant/test map,
sequential acceptance commands, evidence paths and precise retained limitations.

## P3.8 analysis contracts

Canonical analysis tables and Process owner FKs for extraction/publication now exist alongside
legacy AI tables. SchemaSupportedStages and all stage-bearing SQL CHECKs include all eight stages;
real screening/extraction/publication handlers remain unregistered. A pending AI intent still has
`handler_not_activated`; schema support does not authorize provider calls.

`AnalysisRepository` and `ChunkRepository` use typed canonical snapshots without the legacy
AnalysisRun model. Recipe/representation/window inputs and accepted output are immutable in
PostgreSQL; composite FKs prevent cross-version/recipe/window linkage. `core-test-ai` runs T13/T22
in `tests/Feature/ProcessCore/Llm` after the same guarded clean reset and requires actual assertions.
The staged validation/persistence boundary is documented in [estimate extraction](ai/estimate-extraction.md#p3-8-immutable-canonical-analysis-contract).
Production provider send, full fenced checkpoint integration and publication are future owning
slices. No historical test-only analyzer is claimed as production runtime evidence.

At this staging boundary the generic WorkStore refuses an input without its concrete owner field
before INSERT, with a typed configuration error. Resolving extraction's run/window key to the
actual analysis_window_id and integrating its full ancestor locks belongs to the handler cutover.
Schema support alone cannot create a NULL-owner work or activate the stage.


## P3.9 financial and receipt recovery

Permanent `llm_provider_requests`, `llm_provider_calls`, budget accounts/periods/reservations and
financial audit actions are additive. `prc_analysis_llm_requests` pins the exact run/work/attempt
and physical operation; one explicit active linkage replaces latest-call heuristics.
Initial replay identity is the immutable analysis run ID; another ID requires an audited durable
replay request. Registered receipt classifications are pinned with their evidence on first capture.
`AnalysisCallCoordinator` owns full-prefix provisional authorization on the same DB connection.
Provider and mapping IDs are allocated before its retryable transaction; a real SQL 40001 test
proves reuse after rollback. It issues no HTTP capability. The financial claim primitive is only one half of P3.10's joint gate.

Money is exact `numeric(24,8)`. Pinned pricing includes currency/version/units, uncached input,
maximum output and separately billed thinking, fixed/cache charges and full storage TTL bounds.
Account authority serializes reserves across periods. Unknown policy v1 stops new billable claims
at `count >= 1`, disables automatic resend, and optionally bounds all outstanding/in-flight
exposure. Policy changes and conservative settlement require actor/reason and append audit facts.
Rollover preserves original reservations; daily aggregate BillingReconciler output cannot prove
that an individual operation was not billed. Abandon preserves the obligation.

Before claim, interruption revokes the epoch and settles zero (`not_sent`). After claim without
verified raw it preserves the reserve and blocks the work. Verified generation success resumes
only its pinned receipt; cache/file/lookup success recovers an asset directive; a registered
nonbilling policy permits bounded retry. Known billed errors settle money and remain in
`blocked(policy_review)`, carrying evidence without becoming model output. Unregistered or
unproven charge policy remains unknown.

Receipt capture needs no old work lease and never writes business state. A deterministic upload
intent exists before claim. The current backend is bounded local immutable storage: complete
fsynced spool, atomic create-if-absent link, exact envelope/hash/identity verification, 16 MiB
maximum. Object I/O is outside DB transactions. Missing/partial/corrupt/unavailable storage grants
no resend. Remote object-store conditional publication is explicitly unsupported by this slice.
The periodic blocked-provider category verifies put-before-ref receipts without Redis delivery,
then atomically records settlement, checkpoint/state/transition and feeder intent. Its independent
batch of 50 advances missing outcomes by 30 seconds, preserving fairness for later candidates.

`make core-test-ai` includes T15/T22/T30 real PostgreSQL and local immutable-storage assertions:
not_sent/claimed ambiguity, restart and two reconciler processes, all operation purposes, late
cancel/input drift, pause, storage faults, >50 candidates, exact eight-decimal settlement,
concurrent budget contenders, original-period rollover, explicit replay and safe unknown holds.
Recovery tests use a transactional PostgreSQL publication sink and canonical parsing; real
screening/extraction/estimate publication, provider response policies, live prices and joint
provider/network transport remain with their owning P3.10–P3.14 cutovers. Legacy model bindings
and handlers remain unchanged; no paid/live AI is used.

## P3.12 files and context cache

Canonical file/cache carriers are installed with physical-call creation ownership and complete
provider/account/API/content identity. Cache keys also pin model/system/schema/tool policy and
representation ordering. Explicit capability snapshots are part of the sealed wire fingerprint;
the joint gate rechecks their expiry after network waits. GET lookups keep the same joint claim
without a body hash. Asset operations use the financial ledger even at an evidenced zero price.

`core-test-ai` includes T14/T22/T30 local HTTP multipart/cache/lookup/refresh, two-process creator
contention, full TTL reserves, separate usage counters, unknown orphan protection, one proven
nonbillable uncached retry, and purpose-specific asset receipt recovery. Schema inventory and its
rendered block now have one begin/end marker pair. `core-test-install` compares two clean resets.
No real model/account pricing or model semantic accuracy is asserted. The explicit cache is a
transport optimization; production AI handlers, their checkpoint consumption and publication
remain staged until P3.13. See [LLM providers](llm-providers.md) for bounds and REST references.
