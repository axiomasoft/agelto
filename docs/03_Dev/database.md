---
audience: dev
type: reference
status: living
---

# База данных

Полная схема — миграции `database/migrations/` (источник истины, не копируется сюда). Этот раздел —
карта таблиц AI-пайплайна и ключевые инварианты, которые не читаются из одной миграции с ходу.

## Фактический состав схемы

После консолидации P8 (2026-08-08) дерево содержит **63 миграции**: 57 `create_*` с полной
финальной формой таблиц и шесть отдельных инфраструктурных операций. Инфраструктурные файлы
устанавливают/регистрируют pg_partman, задают retention и autovacuum, включают расширения
наблюдаемости, создают исторические партиции и чистят осиротевшие регистрации partman:

- `2026_07_04_100047_setup_pg_partman.php`;
- `2026_07_09_100050_set_pg_partman_retention.php`;
- `2026_07_09_100051_set_hot_partition_autovacuum.php`;
- `2026_08_05_100068_enable_db_observability_extensions.php`;
- `2026_08_05_100074_precreate_historical_partitions.php`;
- `2026_08_05_100076_prune_orphaned_partman_part_config_entries.php`.

В `public` после `migrate:fresh --seed` — **70** обычных и партиционированных родительских
таблиц (физические партиции не считаются). Пять родителей зарегистрированы в pg_partman:
`prc_events`, `prc_revisions`, `llm_calls`, `prc_fetches`, `prc_operation_logs`.

По [D82](../../plans/2026.08.06-CRAWL-HARDEN/decisions/D82-legacy.md) удалены три носителя без
живых доменных писателей/читателей: `prc_daily_stats`, `prc_regions`, `prc_ignore_rules`.
`prc_ignored_items` сохранена: это действующий denylist конкретных закупок. Возврат любого из
трёх удалённых носителей требует новой миграции и восстановления прикладной поверхности.

## Таблицы AI-пайплайна (`prc_ai_*`) и транспорта (`llm_*`)

Домен `Llm` (`llm_*`) — провайдер-агностичный LLM-транспорт, выделенный из закупочного `prc_ai_*` в
собственный домен (D22, P1.5): в нём то, что обслуживает ВЫЗОВ модели (провайдер, маршрут,
инструкция, учёт вызова); предметные AI-сущности (анализ, коррекция, эталон, датасет) остаются в
`prc_ai_*`, ссылаясь на `DocumentVersion`/`Estimate`.

| Таблица | Роль |
|:--|:--|
| `prc_documents` | Документ закупки; `procurement_id` **nullable** (D22 — manual-импорт эталонов вне закупки, `estimate:dataset:import`); lease-колонки (idempotency guard, P4.1) |
| `prc_ai_analyses` | Сессия AI-анализа (`$analysis`) — шаг (`AnalysisStep`: screening/extraction/discovery), статус, `progress` (checkpoint-requeue, D31), `is_shadow` |
| `llm_calls` | Один физический LLM-вызов (usage, `cachedTokens`, `finishReason`, ссылки на request/response-блобы) — домен `Llm`, `App\Models\Llm\LlmCall` |
| `llm_providers` / `llm_routes` | Каталог провайдеров и маршрутов `(document_type, purpose) → model` (роутинг — `llm-providers.md` Д6) — домен `Llm`, `App\Models\Llm\{Provider,LlmRoute}` |
| `llm_prompts` | Рантайм-копия промтов, синхронизируется из обоих корней канона (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) командой `ai:prompts:sync` — `ai/prompts.md` — домен `Llm`, `App\Models\Llm\Prompt` |
| `prc_ai_corrections` | Field-level коррекции оператора (JSONPath + reason-enum) — обучающий контур |
| `prc_ai_dataset_examples` | Immutable gold-снапшоты (SFT/DPO источник) — `ai/training-loop.md` |
| `prc_ai_eval_runs` / `prc_ai_eval_results` | Прогоны `estimate:eval:run` по golden-датасету и их метрики |
| `prc_estimates` / `prc_estimate_items` | Распарсенная смета (`$estimate`) и её позиции (не путать: `prc_items` — позиция объекта закупки/лота); `procurement_id` **nullable** (D98 — обучающий контур работает с документами вне закупки, тот же довод, что и у `prc_documents`); ось «оператор проверил боевой разбор» — `verified_at`/`verified_by` (D89, без писателя внутри плана); `regulatory_base` (`text nullable`, P5.5) — редакция сметных нормативов ЦИТАТОЙ как напечатана, не парсится; `unit_base`/`unit_multiplier` на `prc_estimate_items` и `prc_estimate_resources` — детерминированный разбор `unit` («100 м2» → `м2`/`100`), исключение из D33 (не поле контракта LLM, писатель — нормализатор в `EstimatePersister`, P5.5); `prc_estimate_items.quality` (jsonb, nullable, частичный индекс `WHERE quality IS NOT NULL`, план `2026.07.31-SYS-INTEGRITY` P8.4) — носитель НЕОПРЕДЕЛЁННОСТИ позиции, не величина источника: провенанс ключом верхнего уровня `model`/`validator` (§3.2 `research/12-uncertainty-model.md`), `model` — пометка LLM (`unreadable`, из контракта `extract_rows`), `validator` — построчная аномалия `EstimateValidator` (`arithmetic_mismatch`/`numbering_gap`); `NULL` = позиция прочитана полностью, пишется всегда (в т.ч. явным `NULL` на re-run — иначе пометка предыдущего прогона переживает переизвлечённую строку). `prc_estimates.validation_notes` (jsonb, nullable, `list<string>`, та же миграция) — текстовые заметки `ValidationReport` уровня сметы, ранее вычислявшиеся и терявшиеся |
| `prc_estimate_sections` / `prc_estimate_resources` / `prc_estimate_totals` | Иерархия разделов, ресурсы позиции (труд/машины/материалы/накладные) и дерево итогов сметы (P4.4). Строки `row_type != item` (КС-3, печатные агрегаты позиции, итог раздела) НЕ материализуются — решение владельца (P5.3 §5): `EstimatePersister::persistRows()` их пропускает, величины остаются в `prc_ai_analyses.result`/gold |
| `prc_estimate_work_types` | Вид работ и его иерархия (`parent_id`, per-estimate область), носитель нормативов НР/СП (D34/D90, P4.4) — нормативы полноценно заполняются только gsfx-веткой; на боевом LLM-пути `work_type_id` резолвится через `findOrCreate(estimate_id, title)` (P5.5), запись несёт только `title`, нормативы `null` |

**Критерий подтверждения величины сметной схемы.** Новая колонка/ключ `prc_estimate*` заводится
только при пороге `N ≥ 9` файлов корпуса `estimates/files`, несущих величину, ПЛЮС хотя бы один из
тестов: (а) прямое подтверждение · (б1) величина — операнд арифметической проверки · (б2) поле F1
метрики извлечения · (б3) назван сценарий сортировки/фильтрации витрины; форма носителя
(колонка/jsonb-ключ/производный аксессор) решается тестом (в) — «ровно один носитель на величину»,
«и колонка, и jsonb» для одного факта запрещено. Ранее отклонённая величина переоткрывается только
новым фактом корпуса, не повторной цитатой методики (пример прогона — `regulatory_base`/
`unit_base`/`unit_multiplier` выше).

## Прокси-маршрутизация (`prc_proxies`, `prc_proxy_targets`)

| Таблица | Роль |
|:--|:--|
| `prc_proxies` | Узел: `country` (ISO 3166-1 alpha-2, единственный носитель гео), `scopes` (jsonb-список `App\Enums\Procurement\Proxy\ProxyScope`, GIN-индекс), `is_active` (ручной тумблер оператора), глобальное здоровье breaker'а (`open_until`/`open_count`/`fails_count`/`last_failure_reason`) |
| `prc_proxy_targets` | Per-target здоровье: одна строка на пару (узел, канонический хост цели) — `success_ratio`/`latency_ms_avg` (EWMA), `consecutive_failures`, собственный `open_until`/`open_count`; unique `(proxy_id, target)` |

`prc_crawlers` несёт `use_proxy` (bool, `false` — намеренный прямой запрос) и `proxy_country`
(nullable ISO 3166-1 alpha-2) — привязка профиля к желаемой стране узла; потребляется РОВНО на
скоупе `crawl_listing`, у `crawl_detail`/`document_download` профиля нет (D81, `crawling-pipeline.md`).
Скоупа на профиле нет, единственная точка выдачи —
`App\Services\Procurement\Proxy\ProxyResolver` (`crawling-pipeline.md`). Гео-требование скоупа
(`crawler.proxy.policy.<scope>.required_country`) — отдельный носитель на конфиге, не на профиле;
складывается с `forbidden_countries` союзом внутри резолвера.

## Адресация файлов: колонка `disk` (P6, D51)

Три носителя, у которых файл переживает окно отката (`prc_document_versions.storage_path`,
`prc_ai_fixtures.storage_path`, `prc_estimates.raw_result_ref`), несут `disk` (`nullable`):
`null` означает «диск по конфиг-ключу носителя НА МОМЕНТ ЧТЕНИЯ» (`crawler.documents_disk` /
`ai.fixtures.disk` / `crawler.raw_disk`), непустое значение — конкретное имя диска, записанное в
момент создания строки. Единственная точка, которая читает/пишет это поле — `FileLocationResolver`
(`architecture.md`); прямое обращение к конфиг-ключу диска в обход резолвера — дефект.

**Инвариант материализации (D51):** смена диска-по-умолчанию (флип конфиг-ключа) переживается
только строками, у которых `disk` уже заполнен явно. Перед флипом обязана пройти материализация
(`storage:disk-backfill <носитель> --materialize-only` — проставляет текущее по конфигу имя во ВСЕ
строки с `disk IS NULL`, не читая объектов); флип раньше материализации рвёт адресацию всех
исторических строк, у которых `disk` ещё `null`. Полная процедура смены провайдера — runbook
[`runbooks/storage-provider-switch.md`](runbooks/storage-provider-switch.md).

Имя, которое попадает в колонку, — логическое и поколенческое (`<роль>_v<N>`, без драйвера/
провайдера/региона в строке, D53); сегодняшние `s3`/`estimates`/`estimates_s3` этому не
соответствуют и переименовываются шагом 0 той же процедуры, ДО первой материализации.

## Историчность закупки: сценарии, которые хранит схема

`prc_procurements` — текущая проекция, а `prc_revisions` и `prc_events` сохраняют соответственно
снапшоты и типизированную историю. Поэтому изменение цены, статуса или дат создаёт новую ревизию и
события, а состояние на дату читается как последняя ревизия с `detected_at <=` этой даты. Замена
вложения создаёт новую `DocumentVersion`, не уничтожая анализ старой версии. Одна организация может
иметь несколько ролей в закупке через `org_procurement_parties`; резолв по ИНН/ОГРН не создаёт
отдельную организацию на каждую роль.

Эти сценарии защищают не «идеальную» схему из исторического ТЗ, а реальные инварианты миграций и
тестов. Поиск как отдельная материализованная витрина в старой спецификации не перенесён: в
текущем дереве нет подтверждённого носителя этой поверхности.

## Ключевые инварианты

- **Один активный анализ на версию документа+шаг** (D24): partial unique-индекс
  `prc_ai_analyses_active_version_step_unique` — `(document_version_id, step) WHERE superseded_at IS
  NULL AND is_shadow = false`. Обычный (не partial) `unique(key, version)` — `llm_prompts`, канон
  v1. `prc_estimates` — unique `(analysis_id, ordinal)` (НЕ unique на голый `analysis_id` — один
  анализ порождает N смет, D12).
- **Shadow-анализы** (`is_shadow=true`) — вне активного unique-предиката, не влияют на статусы
  документа; сравнение с локальной LLM (P9.4, `ShadowDispatch`).
- **Re-run/супersede**: после успешного persist нового анализа той же версии документа — старые
  сметы superseded-анализа удаляются в ТОЙ ЖЕ транзакции (`EstimatePersister`), дублей витрины нет;
  история прогона остаётся в `prc_ai_analyses`/`llm_calls`.
- **FK/cascade санация** (D12, аудит F): `prc_procurement_parties.procurement_id` — обычный FK (был
  потерян); иерархия смет и детальных `prc_*` — явный `cascadeOnDelete`, не `RESTRICT` (иначе
  блокирует переразбор/GC).

## Партиционирование и retention (D33, D41, P7.7)

`prc_events`/`prc_revisions`/`llm_calls`/`prc_fetches`/`prc_operation_logs` — пять таблиц,
партиционированных через
`pg_partman` (`docker/postgres/`), retention-окно задаётся в `part_config` — DROP старых партиций по
config-окну. **Хранится вечно** (явное исключение из агрессивного retention): gold-датасет
(`prc_ai_dataset_examples`), `prc_estimates.raw_result`, метаданные `LlmCall` без блобов,
**`prc_revisions`** (D41 — доменный аудит изменений закупок, не эфемерный лог; `retention_keep_table`
или отдельное длинное окно по согласованию владельца). Блобы (`request_ref`/`response_ref`) в MinIO
— под ILM-правила (transition/expire), не под Postgres-retention.

## Наблюдаемость и рантайм-параметры кластера (P7.4)

Кластер `postgres` (dev — `docker-compose.yml`, prod — `docker-compose.prod.yml`, идентично):

- `shared_preload_libraries=pg_stat_statements` + `CREATE EXTENSION IF NOT EXISTS pg_stat_statements`
  (миграция `2026_08_05_100068_enable_db_observability_extensions.php`) — per-query
  `calls`/`total_exec_time`/`wal_bytes`: `SELECT * FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 20`.
- `pgstattuple` (та же миграция, рестарт не требует) — строгий bloat:
  `SELECT * FROM pgstattuple('prc_procurements')`.
- `statement_timeout=600000` (10 мин), `idle_in_transaction_session_timeout=300000` (5 мин — верхняя
  граница самого длинного supervisor-таймаута Horizon, `config/horizon.php`),
  `log_min_duration_statement=1000` (1 с) — глобальные параметры кластера, F-10
  (`research/08-db-review.md` §11); ЗАВИСИМЫЕ от долгих операций команды (backfill/массовые
  миграции) при необходимости повышают `statement_timeout` на уровне сессии
  (`SET LOCAL statement_timeout = ...`), не трогая кластерный дефолт.
- `shm_size: '512mb'` на сервисе `postgres` (параллельные планы, `partman.run_maintenance()`,
  сортировки) — F-11. Сервис `horizon` сохраняет свой `shm_size: '2gb'` (prod) — зарезервирован под
  будущую браузерную автоматизацию в этом контейнере (`CHROME_BIN`, Фаза 8), не переносится.

**Рестарт кластера (даунтайм, порядок и точка отката).** 1) Снять счётчики строк ключевых таблиц
(`prc_procurements`/`prc_documents`/`prc_revisions`) и текущие `SHOW shared_preload_libraries`/
`df -h /dev/shm` — точка отката. 2) Применить правки `docker-compose*.yml`. 3) Пересоздать
контейнер `postgres` (`docker compose up -d postgres`; на среде с рабочим `docker compose` этого
достаточно — расхождение, встреченное при первом прогоне P7.4, ниже). 4) Проверить `healthy`,
сверить счётчики, `SHOW`/`df -h /dev/shm`. 5) `CREATE EXTENSION` — ТОЛЬКО после рестарта с новым
`shared_preload_libraries` (`php artisan migrate`). Откат — вернуть `docker-compose*.yml` к
предыдущей ревизии и пересоздать контейнер; том `pgsql_data` не трогается ни на одном шаге.

Кэш (D73/D75): `config/cache.php` fallback `env('CACHE_STORE', 'redis')` — контекст без переменной
окружения (artisan с хоста, CI, будущий контейнер без compose-блока) пишет в Redis, не в Postgres.
Таблица `cache` сохранена (удаление — отдельное решение владельца, `research/08-db-review.md` §11.1);
эффективное значение — `php artisan config:show cache.default`.

## Смежное

- `architecture.md` — домены/префиксы.
- ADR [`adr/0001-prc-megadomain.md`](adr/0001-prc-megadomain.md) — почему единый `prc_`-мегадомен.
- Статусы понятным для оператора языком — `docs/04_Operators/reference/statuses.md` (P10.4).
