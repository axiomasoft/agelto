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

Проверки: `tests/Feature/ProcessCore/Execution/T11AdmissionFairnessTest.php` (parent+child W,
refill, cohort→stage RR, age/priority/manual FIFO, cap и dynamic cohort, deferred/blocked,
pause/handler hold, supersede), `T09ClaimDeliveryTest.php` (claim, duplicate/stale/expired/
terminal delivery, parent+child C CapacityWait, pause/hold, membership incident, порядок lock
tiers, setMode) и многопроцессный `tests/Integration/ProcessCore/Concurrency/T24PauseClaimConcurrencyTest.php`
(pause против 10 параллельных claims без deadlock и без grant после commit pause; два feeder ≤ W).
`make core-test-execution` выполняет эти каталоги после clean reset. Finalize/heartbeat/recovery
(T09 полностью), queue/daemons и document/load T11/T16 — следующие пункты.
