---
audience: dev
type: runbook
status: living
---

# Process: проекции, run reducer и обслуживание (P1.15)

Контракт — `observability#s03/#s04/#s08`. Проекции — производные: ledger `prc_process_transitions`
остаётся единственным источником фактов, rebuild и retention не запускают work и внешние эффекты.

## Registry, receipts, totals

- `ProjectionRegistry` — versioned набор reducers (`work_transitions`, `run_transitions`,
  `governance_transitions`). Каждый reducer чистый: один immutable event → приращения СВОИХ
  counter-серий из закрытого `MetricCatalog`. Серия принадлежит ровно одному reducer; измерения —
  закрытые перечисления либо ограниченный platform code (`manual` или `[a-z][a-z0-9_]{0,31}`).
  ID, URL и произвольный текст как label отвергаются (`ProjectionSchemaError`).
- `prc_process_projection_states` — gate и active generation, `catalog_hash` версии reducer,
  `history_complete`. `prc_process_projection_receipts (projector_key, event_id)` — глобальный dedup
  независимо от generation. `prc_process_metric_totals` — counters generation.
- Live apply (`MetricProjector`): одна короткая tx на reducer — state `FOR SHARE` → source event →
  `INSERT receipt` → `UPSERT counters`. Receipt и counters атомарны: дубликат — Duplicate, crash между
  ними откатывает оба, порядок доставки не важен (скалярного last-seq нет). State в maintenance или
  занятый `FOR UPDATE` (короткий `lock_timeout`) — Maintenance: событие остаётся unapplied.
- Current gauges (прогресс run, статус) в totals НЕ хранятся: их читает `RunProjector` из
  authoritative rows, поэтому старое или переставленное событие не возвращает статус назад.
  Counters считаются по времени перехода, а не по текущему состоянию work.
- `ProjectorDaemon` (`process:daemon project`, tick 1 s) claim-ит только kind `projection`. Сообщение
  подтверждается после receipts всех получателей. Неподдерживаемая schema, неверный payload,
  `catalog_hash mismatch` и событие вне каталога → quarantine (`quarantined_projection_*`, час),
  incident `projection_schema_error` (один на код) и `outbox_quarantined` в readiness; остальные
  event IDs продолжают проецироваться. Maintenance откладывает сообщение (`projection_maintenance`,
  5 s) без degraded. Source, удалённый retention, подтверждается с нулевым приращением.

## Run reducer (`RunStateReducer`)

Состояние run выводится из run row и required links; observes run не удерживает.
Порядок: explicit cancel → `cancelled`; required `ready/admitted/running` → `running`; только
`deferred/blocked` → `waiting`; иначе все required terminal: discovery `collecting` → `running`
(`requested` до первой активации), закрыт → `completed`, а при `failed_item`, индивидуально
cancelled/superseded без замены required или `incomplete` discovery → `completed_with_issues`.
Open empty не `completed`. `percent` — `null`, пока discovery открыт. `executed` — только
`relation=created`; shared required work считается в каждом run, нуждающемся в нём.

## Bounded rebuild

```bash
php artisan process:metrics-rebuild <projector>
php artisan process:metrics-rebuild-resume <projector> --expected-token=<uuid>
```

Rebuild: (A) state `FOR UPDATE` → `maintenance` + token; (B–E) одна REPEATABLE READ tx — один MVCC
snapshot, новая generation и receipts в ней же, flip CAS по token и прежней generation; (G) отдельная
CAS снимает gate своим token. Cutoff по max(id)/timestamp/sequence не используется: события,
committed после snapshot, получают receipt от обычного consumer. Отказ ничего не меняет
(прежняя generation и receipts целы): `busy`, `history_unavailable`, `catalog_mismatch`,
`too_large` (> `process.projection.rebuild_max_events`, 100000), `timeout`
(`rebuild_deadline_seconds`, 30), `unsupported_fact`, `inconsistent`. Это bounded maintenance v1,
не SLA для большого архива.

На PostgreSQL 17 snapshot-транзакция получает `transaction_timeout` на оставшийся общий deadline,
помимо `statement_timeout` и проверки после flip. Сервер откатывает её и закрывает соединение даже
при остановке PHP после последней проверки перед COMMIT. Rebuild пересоздаёт соединение, не повторяя
сам rebuild. Если transport потерял timeout diagnostic, `timeout` подтверждается только после deadline
и проверки прежней generation и собственного maintenance token под state `FOR UPDATE`; неизвестный
исход COMMIT остаётся ошибкой. Снятие gate выполняется отдельной CAS только с собственным token.

Если процесс погиб и gate остался (`mode=maintenance`), `rebuild-resume` с token из state
идемпотентно возвращает `active`, не меняя totals и receipts. Автоматического снятия по TTL нет.

## Retention

События, на которые ссылаются receipts, удаляет только `ProjectionRetention::prune()`: под
`FOR UPDATE` всех затронутых state rows в sorted `projector_key`; maintenance откладывает batch
целиком; событие удаляется, если receipts всех получателей есть и projection fanout подтверждён.
Затронутые projectors необратимо получают `history_complete=false` — дальше rebuild отказывает
(`history_unavailable`), live totals не уменьшаются. Выбор устаревших событий (active incidents,
admin audit, provenance, dedup) — политика `RetentionService`; FK receipts `ON DELETE RESTRICT`.

## Проверка

`make core-test-observability` (T18/T20/T46). Сброс только isolated test DB.

## Операторские команды (P1.17)

Контракт — `observability#s04`, `execution#handler-fault-budget`. UI и CLI вызывают один шлюз
`ProcessOperatorCommands`: RBAC по `ProcessControlPermission`, обязательный `reason`, actor из `User`,
optimistic version, audit transition с actor/before/after/reason. Прямых записей из Filament/Pulse нет
(`ProcessOperatorBoundaryTest`).

| Команда | Version fence | Право | Роль по умолчанию |
|---|---|---|---|
| `pauseScope` / `resumeScope` | `control_version` | `pause_scope` / `resume_scope` | Operator |
| `cancelWork` | `transition_seq` work | `cancel_work` | Operator |
| `cancelRun` | `transition_seq` run | `cancel_run` | Operator |
| `acknowledgeIncident` | `transition_seq` incident | `acknowledge_incident` | Operator |
| `retryHandlerFaultedWork` | `transition_seq` work + `commandId` | `retry_handler_fault` | только SuperAdmin |

`ResumeScope` снимает только manual pause этой области: `blocked(handler_fault_limit)`, health и handler
holds он не трогает. Повтор `commandId` возвращает `Duplicate` и прежний successor без второго intent.

CLI: `process:control {pause|resume|cancel-work|cancel-run|retry-fault|ack-incident} {target} --user= --reason= --expected=`
(код 0 — settled, 1 — отказ без записей, 2 — аргументы или права). UI: «Система → Процессы: управление» и
«Процессы: инциденты»; расширение drilldown на runs/work остаётся за P1.17.

## Retention transition history (P1.18)

`process:prune-history [--dry-run]` удаляет один bounded batch через `RetentionService`: терминальные work,
resolved incidents, runs без нетерминального required work, старше `process.retention.transition_days`
(минимум 365; operator audit — `audit_days`). Хвост истории каждой сущности, активные incidents и события без
receipt/fanout не удаляются; rebuild в maintenance откладывает batch.
