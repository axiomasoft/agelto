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
assignment закрыт, head/cycle поля procurement/document не fillable. Claim, heartbeat,
checkpoint, finalize, admission, recovery, controls mode mutation (pause/resume), handler hold
detector, outbox consumers и daemons добавляют P1.7–P1.11; до этого ни один runtime consumer не
читает и не пишет ledger.
