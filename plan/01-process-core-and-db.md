# План 01: Process Core, демоны, очереди и БД (PostgreSQL / Retention / Индексы)

**Волна внедрения:** W1 (критические и высокоприоритетные), W5 (структурный рефакторинг)  
**Затрагиваемые слои:** `app/Services/Procurement/Process/**`, `app/Repositories/Procurement/Process/**`, `app/Console/Commands/Process/**`, `database/migrations/**`, `config/process.php`, `config/partitioning.php`, `routes/console.php`.

---

## 1. Критические и высокоприоритетные задачи

### [CRITICAL / HIGH] H3. Retention для `prc_process_outbox` и высокообъёмных таблиц телеметрии
- **Файлы:**
  - `database/migrations/2026_10_25_100000_create_prc_process_ledger_tables.php:370-386`
  - `database/migrations/2026_10_25_100001_create_prc_request_observation_tables.php:35-89`
  - `database/migrations/2026_10_25_100000_create_prc_process_network_tables.php:236-293`
  - `app/Repositories/Procurement/Process/OutboxRepository.php`
  - `app/Console/Commands/Process/ProcessRetentionCommand.php:15-30`
  - `config/partitioning.php:15-18`
- **Проблема:**
  - В кодовой базе нет ни одного `DELETE` для `prc_process_outbox`: все опубликованные `work_wake`, `feeder_wake` и `projection` (с `jsonb`-payload и `UNIQUE(dedup_key)`) копятся вечно.
  - `prc_request_attempts`, `prc_observations`, `prc_observation_applications`, `prc_network_demands` и `prc_network_permits` не партиционируются (`config/partitioning.php` покрывает только `prc_events` и `prc_revisions`) и не очищаются в `process:retention`.
- **План исправления:**
  1. Добавить миграцию с частичным индексом `CREATE INDEX prc_process_outbox_published_idx ON prc_process_outbox (published_at, message_id) WHERE published_at IS NOT NULL`.
  2. Реализовать `OutboxRepository::prunePublished(int $retentionDays, int $limit): int` с удалением батчами `WHERE published_at IS NOT NULL AND published_at < clock_timestamp() - make_interval(days => ?)` (например, 7–14 дней; идемпотентность проекций уже гарантируется `prc_process_projection_receipts`).
  3. Вызывать `prunePublished()` в `ProcessRetentionCommand` с ограничением по числу батчей за тик.
  4. Настроить помесячное партиционирование (или bounded retention + `autovacuum_vacuum_scale_factor = 0.02`, `fillfactor = 80`) для `prc_request_attempts`, `prc_observations` и `prc_process_outbox`.
- **Верификация:** Feature-тест retention: опубликованные строки старше порога удаляются батчами, неопубликованные / quarantined / свежие остаются нетронутыми.

---

### [HIGH] H1. Изоляция poison-pill в `ProjectorDaemon`
- **Файлы:**
  - `app/Services/Procurement/Process/ProjectorDaemon.php:56-67`
  - `app/Repositories/Procurement/Process/OutboxRepository.php:75-88, 114-129`
- **Проблема:**
  При любом неперехваченном `\Throwable` в `$this->handle($message)` (ошибка редьюсера, `LogicException`, constraint-ошибка) `catch` вызывает `$this->release(array_slice($messages, $index))` и пробрасывает исключение в `DaemonLoop`. `OutboxRepository::releaseClaim()` сбрасывает `claim_token`/`claim_until`, **не сдвигая `available_at`**. На следующем тике `ORDER BY available_at, message_id LIMIT 100` снова берёт то же битое сообщение первым → вечная блокировка всех метрик и `ProjectionRetention`.
- **План исправления:**
  1. Классифицировать ошибку через `DatabaseFailureClassifier::isTransient($failure)`: при транзиентном сбое БД освобождать остаток батча и отдавать управление `DaemonLoop` backoff.
  2. При не-транзиентном сбое отдельного сообщения: изолировать его, вызывать `OutboxRepository::defer()` с экспоненциальным backoff от `$message->attempts`, а при достижении порога (например, `attempts >= 5`) переводить в карантин `$this->quarantine($message, 'apply_failed', $eventId)` и продолжать обработку остальных 99 сообщений батча.
- **Верификация:** Feature-тест: сообщение №1 бросает `RuntimeException` в редьюсере, сообщение №2 валидно → №2 подтверждено, №1 уходит в defer и затем в карантин после лимита попыток.

---

### [HIGH] H2. Устранение Full Table Scan в `InvariantRepository::readBatch()`
- **Файлы:**
  - `app/Repositories/Procurement/Process/InvariantRepository.php:41-117`
  - `app/Services/Procurement/Process/InvariantScan.php:42`
- **Проблема:**
  Каждые 60 секунд `InvariantScan` в `ProcessTransaction` (`statement_timeout = 5s`) берёт `FOR SHARE` на `prc_control_scopes` и **все строки `prc_process_lanes`**, после чего:
  - сканирует всю `prc_process_work` (включая все исторические `succeeded`/`failed_item`/`cancelled`/`superseded`) с 3 коррелированными подзапросами на каждую строку;
  - сканирует все `state = 'released'` строки `prc_admission_groups` с `EXISTS` в `prc_process_reservations`.
  Это замораживает все `FOR UPDATE` на lanes до 5 секунд каждую минуту и начнёт стабильно падать по `57014 statement_timeout` при росте истории.
- **План исправления:**
  1. Ограничить CTE `work_facts` активными/потенциально нарушающими кандидатами: `WHERE w.state IN ('admitted', 'running') OR EXISTS (SELECT 1 FROM prc_admission_groups g WHERE g.work_id = w.id AND g.state <> 'released') OR EXISTS (SELECT 1 FROM prc_process_attempts a WHERE a.work_id = w.id AND (a.finished_at IS NULL OR (a.finished_at IS NULL) <> (a.terminal_kind IS NULL)))` с частичными индексами.
  2. Инвертировать ветку `invariant_released_members`, чтобы идти от маленькой таблицы `prc_process_reservations`: `SELECT DISTINCT 'group:' || g.id, 'invariant_released_members' FROM prc_process_reservations r JOIN prc_admission_groups g ON g.id = r.admission_group_id WHERE g.state = 'released'`.
- **Верификация:** `EXPLAIN (ANALYZE, BUFFERS)` на 100k+ исторических строк `prc_process_work` / `prc_admission_groups` + `make core-test-concurrency`.

---

### [HIGH] H4. Устранение `id::text = ?` в `IntentRepository::activateNextLocked()`
- **Файлы:** `app/Repositories/Procurement/Process/IntentRepository.php:112`
- **Проблема:**
  `SELECT {$headColumn} FROM {$table} WHERE id::text = ?` выполняется внутри `ProcessTransaction` под полными префиксными `FOR UPDATE` блокировками (`WorkFinalizationRepository::finishLocked`, `WorkMutationRepository::cancelWork`). Приведение столбца `id::text` отключает PK-индекс и вызывает Seq Scan таблицы субъекта.
- **План исправления:** Приводить параметр, а не колонку: `WHERE id = ?::bigint` для `CrawlBlock`/`Procurement`/`Document`/`DocumentVersion` и `WHERE id = ?::uuid` для `AnalysisRun`/`AnalysisWindow`.
- **Верификация:** `EXPLAIN` подтверждает `Index Scan` по PK для `bigint` и `uuid` таблиц + `make core-test-execution`.

---

### [HIGH] DB-индексы и партиционирование
- **Файлы:**
  - `database/migrations/2026_10_25_100000_create_prc_process_ledger_tables.php`
  - `database/migrations/2026_11_02_100000_create_prc_analysis_tables.php`
  - `database/migrations/2026_11_02_100001_create_llm_request_ledger.php`
  - `database/migrations/2026_10_25_100001_create_prc_request_observation_tables.php`
- **Проблема:** Отсутствуют критичные индексы для горячих запросов демонов, retention, Pulse, Filament и AI-отчётов:
  1. `prc_process_transitions` — нет индекса по `occurred_at` (Seq Scan в `RetentionService`, `OperationLogsTable`, `PipelineStagesCard`, `ProcessDiagnosticsInspector`).
  2. `llm_provider_calls` — нет индекса по `coalesce(sent_at, send_claimed_at)` (Seq Scan в `LlmSpendChartWidget` и `LlmSpendCard`).
  3. `prc_analysis_llm_requests` — нет индекса по `analysis_run_id` (Seq Scan в `LEFT JOIN LATERAL` `AnalysisRunReadRepository::decorate()`).
  4. `prc_analysis_runs` — нет индекса с ведущим `document_version_id` (`UNIQUE(id, document_version_id)` не помогает `WHERE document_version_id = ?`).
  5. `prc_observations` (только PK) и `prc_request_attempts` (нет по `started_at`), `prc_process_work(updated_at DESC)`, `prc_process_work(procurement_id, created_at DESC, id DESC) WHERE stage = 'detail' AND procurement_id IS NOT NULL`, `prc_process_runs(requested_at DESC)`, `prc_process_incidents(state, last_seen_at DESC)`, `prc_process_run_work(work_id)`, `prc_operation_intents(state, next_due_at)`, `prc_status_refreshes(next_due_at)`.
- **План исправления:** Создать прямую миграцию (с `down()` и обновлением `tests/Support/ProcessCore/schema-inventory.json` и `tests/Fixtures/ProcessCore/manifest.json`), добавляющую перечисленные индексы.
- **Верификация:** `EXPLAIN` по каждому горячему запросу + `make core-quality` + `make core-test-install`.

---

## 2. Средний приоритет

### [MEDIUM] M1. Heartbeat внутри длинных тиков демонов
- **Файлы:** `app/Services/Procurement/Process/DaemonLoop.php:67-99`, `ReconcileDaemon.php:48-62`, `config/process.php:101-113`
- **Проблема:** `DaemonLoop` шлёт heartbeat только после возврата из `tick()`. При `live_seconds = 30` один тяжёлый тик `ReconcileDaemon` (6 категорий, включая внешний `inspect()` провайдера/хранилища) может превысить 30s → ложный `DaemonHealth::Down` и рестарт контейнера в середине recovery.
- **Исправление:** Передавать колбэк heartbeat в `ProcessDaemon::tick()` и вызывать его между категориями `ReconcileDaemon` / батчами `ProjectorDaemon` и `RelayDaemon` при превышении `heartbeat_seconds`.

### [MEDIUM] M2. Устранение двойного вызова `settleLocked()`
- **Файлы:** `app/Repositories/Procurement/Process/RecoveryRepository.php:353-363, 401-409`
- **Проблема:** В `recoverProvider()` и `recoverCancelledProvider()` при наличии квитанции и разрешённого `finding` метод `$resolver->settleLocked($finding)` вызывается дважды в одной транзакции.
- **Исправление:** Отслеживать флаг `$settled` или выполнять ранний `settleLocked()` только в ветке `BlockedUnknown`. Покрыть unit-тестом со spy-объектом.

### [MEDIUM] M3. Оптимизация N+1 и времени блокировки lanes в `AdmissionRepository`
- **Файлы:** `app/Repositories/Procurement/Process/AdmissionRepository.php:70-71, 152-281`
- **Проблема:** `parentEligiblePairs()` делает 1 SQL на каждую пару `(stage, cohort)` в `prc_process_handler_holds` (N+1); `readEligibilitySnapshots()` грузит полный `AuthoritySnapshot` (7+ запросов) ради 3 таблиц; `admitLocked()` повторно вызывает `parentEligiblePairs()` уже **под `FOR UPDATE` блокировкой всех lanes**.
- **Исправление:** Батчевый запрос `WHERE stage IN (...)`, облегчённый `readEligibilityAuthority()`, переиспользование уже заблокированных `FOR SHARE` данных handler holds внутри `lockedEligibleCohorts()`.

### [MEDIUM] M4. Накопление `feeder_wake` при частичном сбое producer в `FeederDaemon`
- **Файлы:** `app/Services/Procurement/Process/FeederDaemon.php:66-116`
- **Проблема:** При падении одного producer остальные lanes успешно коммитят admissions, но rethrow в строке 96 освобождает **все** claimed `feeder_wake` без сдвига `available_at` и не обновляет `$nextFullTickNs`, вызывая рост `attempts` и ложные `stale_outbox` алерты.
- **Исправление:** Помечать `published` те `feeder_wake`, чьи lanes успешно отработали `WorkFeeder::tick()`, и возвращать `DaemonTick` с ошибкой / backoff для сбойного producer.

### [MEDIUM] M5. Батчевая очистка `ProjectionRetention::prune()` и многобатчевый `process:prune-history`
- **Файлы:** `app/Services/Procurement/Process/ProjectionRetention.php:50-85`, `ProjectionRepository.php:311-337`, `PruneHistoryCommand.php:34`, `routes/console.php:64-67`
- **Проблема:** До ~2 000 последовательных SQL-запросов (4 × 500) внутри одной 5-секундной транзакции под `FOR UPDATE` на `prc_process_projection_states`; пропускная способность ограничена 500 строками/час (12 000/сутки).
- **Исправление:** Переписать на 4 bulk-запроса (`WHERE event_id = ANY(?::uuid[])`) и добавить `--max-batches=20` / временной бюджет (10s) в `process:prune-history`.

### [MEDIUM] M6. Bulk `UPDATE` в `OutboxRepository::releaseClaims()` и `FeederDaemon::consume()`
- **Файлы:** `app/Repositories/Procurement/Process/OutboxRepository.php:104-129`, `FeederDaemon.php:109-114`
- **Проблема:** До 100 отдельных `UPDATE` в цикле (включая error-path).
- **Исправление:** Перевести на один запрос `WHERE message_id = ANY(?::uuid[]) AND kind = ? AND published_at IS NULL AND claim_token = ?`.

---

## 3. Низкий приоритет и структурный рефакторинг (W5)

- **L1 (`clock_timestamp()` в CLI/диагностике):** заменить `new DateTimeImmutable` в `PruneHistoryCommand.php:27` на `ProcessClock::dbNow()`, и PHP `now()->subMinutes/subHours` в `ProcessDiagnosticsInspector.php:123,155,179,202,294,339,390` на SQL `clock_timestamp() - make_interval(...)`.
- **L2 (`ProcessAuthoritySync`):** обернуть `ensureScopes`/`syncLanes`/`register` (`ProcessAuthoritySync.php:38,41,46`) в `ProcessTransaction::run()` вместо голого `DB::transaction(..., attempts: 1)`.
- **L3 (`cheatsheet.md` vs `config/process.php`):** синхронизировать значения `slice` в `.ai/guidelines/cheatsheet.md:14,17-20` (`240`/`300`/`270`).
- **L4 (`WorkFinalizer::apply()`):** явно передавать `$lease->handlerVersion` в `readAuthoritySnapshot()` (`WorkFinalizer.php:117`).
- **L5 (Рефакторинг hotspot-файлов):**
  - Разбить `ProcessDiagnosticsInspector.php` (810 LOC) на `QueueCoverageInspector`, `PipelineStallInspector`, `AiQualityInspector`.
  - Заменить magic-key массивы `$ids` и 10–12 позиционных аргументов в `WorkStoreRepository::storeIntent/storeWork` и `TransitionRepository::record*` на компактные readonly DTO (`FinalizationIds`, `RecoveryIds`, `ClaimIds`, `StoreWorkCommand`).
  - Добавить `down()` в миграции `2026_11_11_100000_create_prc_proxyma_adapter_links.php` и `2026_11_11_100001_create_prc_proxyma_transfer_records.php`.
