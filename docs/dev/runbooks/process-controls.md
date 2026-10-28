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
Причина каждого Pause/Resume сохраняется в `details.reason` неизменяемого transition (`details_schema_version=1`)
в той же транзакции, что и режим области; последующая смена `change_reason` в области не стирает историю причин.

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
(код 0 — settled, 1 — отказ без записей, 2 — аргументы или права). UI, раздел «Система»: «Процессы: управление»
(Pause/Resume), «Процессы: инциденты» (Acknowledge), «Процессы: запуски» (учёт required work, полнота discovery,
`CancelRun`) и «Процессы: работа» (почему ждёт, следующая попытка, число попыток и отказов по объекту,
`CancelWork`; `RetryHandlerFaultedWork` показывается только на `blocked(handler_fault_limit)` и только при праве
`retry_handler_fault`). Действие строки сохраняет версию записи (`control_version` для Pause/Resume,
`transition_seq` для остальных команд) при открытии подтверждения в
подписанном токене (привязан к оператору, action и записи; подделка или перенос отклоняются с предупреждением), и
команда получает именно её: если запись изменилась до подтверждения, шлюз вернёт `version_conflict`, а не применит
устаревшее намерение. `commandId` retry выводится из подтверждённой версии, поэтому повтор по той же версии
дедуплицируется, а после перечтения строки даётся новая версия и новый `commandId`. Причина — от 1 до 200 символов
(как в `WorkMutationRepository`); недопустимая причина возвращается в форму, а не исключением. Колонка «Состояние» в
«Процессы: запуски» показывает состояние `RunProjector` (в т. ч. «Завершён с проблемами» для failed/cancelled/superseded
required work и неполного discovery). Legacy sidebar-раздел источников и его badge
остаются до P3.16–P3.19; `T17ProcessControlResourcesTest` проверяет полный sidebar на промежуточном baseline.

## Retention transition history (P1.18)

`process:prune-history [--dry-run]` удаляет один bounded batch через `RetentionService`: терминальные work,
resolved incidents, runs без нетерминального required work, старше `process.retention.transition_days`
(минимум 365; operator audit — `audit_days`). Хвост истории каждой сущности, активные incidents и события без
receipt/fanout не удаляются; rebuild в maintenance откладывает batch.

Срок считается в сутках по 24 ч от `now` в UTC (граница строгая: событие ровно на cutoff остаётся), не в календарных
месяцах и не по wall-clock зоны с DST. `--dry-run` и реальный запуск после каждого batch сообщают полноту истории:
если хотя бы один projector стал `history_complete=false`, команда пишет, что rebuild недоступен
(`history_unavailable`), — архив не выдаёт урезанную историю за полную; live totals при этом не уменьшаются.

Crash evidence (T20, реальные PostgreSQL connections): SIGKILL процесса после первого `DELETE` ledger event, сбой при
пометке `history_complete` и гонка `prune`↔`apply` откатывают batch целиком (источник, receipts и
`history_complete` не тронуты); повтор завершает batch и затем no-op. Новый receipt, появившийся одновременно с
удалением, оставляет событие, пока fanout не подтверждён. Eligibility кандидата вычисляется до locks по монотонным
предикатам (терминальная work, resolved incident, закрытый discovery, не последнее transition); authority rows
retention не трогает.

Границы пункта: двухфазный GC storage-объектов (raw artifacts, `PruneCrawlerHistory`) принадлежит P3.1 — он не часть
Process ledger. Запись `process:prune-history` в расписание (`routes/console.php`, hourly по bp-daemons) выполняется при
cutover P3.16–P3.19: до него команда запускается оператором вручную.
