# P7.1 — Машинный инвентарь схемы и рантайма БД (слой 1: факты)

**Слой 1 — только факты: запрос, вывод, время снятия.** Вердикты, severity, приоритеты и класс
правки — `research/08-db-review.md` (слой 2). Здесь нет ни одной оценки.

| Поле | Значение |
|:--|:--|
| Источник | КАТАЛОГ живой БД (не файлы миграций — 45 из 56 таблиц создаются через `$this->table()`, §13.1 разведки) |
| БД | `agelto` (боевые данные смоук-прогона P4.7 и контрольного прогона P4.15) |
| Подключение | `docker exec agelto_postgres psql -U agelto -d agelto` — `docker compose exec` в этом окружении падает на интерполяции без `MINIO_ROOT_PASSWORD` (workaround handoff'а плана) |
| Tree-якорь | `6ddaa62` (`git rev-parse --short HEAD`) |
| Время снятия | 2026-08-05 14:27–14:36 UTC (`SELECT now()` → `2026-08-05 14:27:55.816282+00`) |
| Версия сервера | `17.10 (Debian 17.10-1.pgdg13+1)` (`SELECT current_setting('server_version')`) |

Все числа ниже сняты в этом окне и на этом якоре. Команда приведена рядом с каждым.

---

## 1. Инвентарь: сколько чего в схеме

### 1.1 Число таблиц (клауза Validation)

```
docker exec agelto_postgres psql -U agelto -d agelto -At -c \
  "SELECT count(*) FROM pg_tables WHERE schemaname='public'"
```

```
85
```

Разложение того же числа (`pg_class.relkind` × принадлежность к `pg_inherits`):

```sql
SELECT c.relkind,
       count(*) FILTER (WHERE i.inhrelid IS NULL) AS not_a_partition,
       count(*) FILTER (WHERE i.inhrelid IS NOT NULL) AS is_partition
FROM pg_class c
JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits i ON i.inhrelid=c.oid
WHERE c.relkind IN ('r','p') GROUP BY c.relkind ORDER BY 1;
```

```
 relkind | not_a_partition | is_partition
---------+-----------------+--------------
 p       |               5 |            0
 r       |              65 |           15
```

**85 = 5 партиционированных родителей + 65 обычных таблиц + 15 партиций.** Логических таблиц —
**70**; число в отчёте слоя 2 совпадает с каталогом.

### 1.2 Разложение 70 логических таблиц по префиксам

```sql
SELECT CASE WHEN c.relname LIKE 'prc\_%' THEN 'prc_' WHEN c.relname LIKE 'ref\_%' THEN 'ref_'
            WHEN c.relname LIKE 'llm\_%' THEN 'llm_' WHEN c.relname LIKE 'org\_%' THEN 'org_'
            WHEN c.relname LIKE 'pulse\_%' THEN 'pulse_' ELSE 'system' END AS prefix,
       c.relkind, count(*) AS n
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits i ON i.inhrelid=c.oid
WHERE c.relkind IN ('r','p') AND i.inhrelid IS NULL GROUP BY 1,2 ORDER BY 1,2;
```

```
 prefix | relkind | n
--------+---------+----
 llm_   | p       |  1
 llm_   | r       |  3
 org_   | r       |  1
 prc_   | p       |  4
 prc_   | r       | 40
 pulse_ | r       |  3
 ref_   | r       |  4
 system | r       | 14
```

### 1.3 Колонки, констрейнты, индексы

```sql
-- колонки (без партиций)
SELECT count(*) FILTER (WHERE is_nullable='YES') AS nullable,
       count(*) FILTER (WHERE is_nullable='NO')  AS not_null,
       count(*) FILTER (WHERE is_nullable='YES' AND column_default IS NOT NULL) AS nullable_with_default,
       count(*) FILTER (WHERE is_nullable='NO'  AND column_default IS NOT NULL) AS notnull_with_default,
       count(*) AS total
FROM information_schema.columns c JOIN pg_class pc ON pc.relname=c.table_name
JOIN pg_namespace n ON n.oid=pc.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits i ON i.inhrelid=pc.oid
WHERE c.table_schema='public' AND i.inhrelid IS NULL AND pc.relkind IN ('r','p');
```

```
 nullable | not_null | nullable_with_default | notnull_with_default | total
----------+----------+-----------------------+----------------------+-------
      481 |      384 |                     0 |                  117 |   865
```

```sql
-- констрейнты (без партиций)
SELECT contype, count(*) FROM pg_constraint c JOIN pg_class r ON r.oid=c.conrelid
JOIN pg_namespace n ON n.oid=r.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits i ON i.inhrelid=c.conrelid WHERE i.inhrelid IS NULL GROUP BY 1 ORDER BY 1;
```

```
 contype | count
---------+-------
 c       |    14   -- CHECK
 f       |    86   -- FOREIGN KEY
 p       |    69   -- PRIMARY KEY
 u       |    42   -- UNIQUE
```

```sql
SELECT count(*) FILTER (WHERE indisunique) AS unique_idx,
       count(*) FILTER (WHERE indpred IS NOT NULL) AS partial_idx
FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid
JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits inh ON inh.inhrelid=c.oid WHERE inh.inhrelid IS NULL;
```

```
 unique_idx | partial_idx
------------+-------------
        120 |           7
```

```
SELECT count(*) AS all_indexes, count(*) FILTER (WHERE idx_scan=0) AS never_scanned
FROM pg_stat_user_indexes;
```

```
 all_indexes | never_scanned
-------------+---------------
         257 |           183
```

### 1.4 Состояние миграций

```
docker exec agelto_app php artisan migrate:status | grep -c "Ran"   → 76
docker exec agelto_app php artisan migrate:status | grep -i pending → (пусто)
ls database/migrations/ | wc -l                                     → 71
```

Незаявленных/непрогнанных миграций нет. Последние применённые — `…100064_add_regulatory_base_to_prc_estimates_table`
(P5.5), `…100065/100066/100067_add_disk_*` (P6.2). **Миграции-носителя метрики отставания от P4.6 в
дереве нет** — сверка предпосылки D64 (item метрику носителями обошёл, D122).

### 1.5 Фактические объёмы (топ по размеру, без партиций-детей)

```sql
SELECT c.relname AS tbl, s.n_live_tup AS live, s.n_dead_tup AS dead,
       pg_size_pretty(pg_total_relation_size(c.oid)) AS total,
       s.last_autovacuum, s.autovacuum_count
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname='public'
LEFT JOIN pg_stat_user_tables s ON s.relid=c.oid
LEFT JOIN pg_inherits i ON i.inhrelid=c.oid
WHERE c.relkind IN ('r','p') AND i.inhrelid IS NULL AND pg_total_relation_size(c.oid) > 16384
ORDER BY pg_total_relation_size(c.oid) DESC LIMIT 12;
```

```
             tbl              | live  | dead |  total  |        last_autovacuum        | autovacuum_count
------------------------------+-------+------+---------+-------------------------------+------------------
 prc_procurements             | 17943 |  778 | 18 MB   | 2026-08-05 12:23:43.440074+00 |               33
 pulse_aggregates             |  4186 |  185 | 2944 kB | 2026-08-05 12:24:43.637107+00 |                2
 pulse_entries                |   598 |    0 | 496 kB  |                               |                0
 failed_jobs                  |    19 |    0 | 152 kB  |                               |                0
 prc_documents                |    66 |    5 | 144 kB  |                               |                0
 llm_prompts                  |     9 |    0 | 128 kB  |                               |                0
 prc_items                    |    77 |    0 | 120 kB  |                               |                0
 prc_document_versions        |    62 |    0 | 120 kB  |                               |                0
 org_organizations            |     2 |    0 | 112 kB  |                               |                0
 prc_document_representations |    31 |    0 | 104 kB  |                               |                0
 prc_proxies                  |     3 |   31 | 96 kB   |                               |                0
 prc_proxy_targets            |     3 |   16 | 80 kB   | 2026-08-05 13:41:44.614616+00 |                9
```

Партиционированные (сумма по партициям):

```sql
SELECT p.relname AS parent, count(*) AS partitions,
       pg_size_pretty(sum(pg_total_relation_size(c.oid))) AS total
FROM pg_inherits i JOIN pg_class c ON c.oid=i.inhrelid JOIN pg_class p ON p.oid=i.inhparent
WHERE p.relkind='p' GROUP BY 1 ORDER BY 1;
```

```
       parent       | partitions |  total
--------------------+------------+---------
 llm_calls          |          3 | 120 kB
 prc_events         |          3 | 3168 kB
 prc_fetches        |          3 | 288 kB
 prc_operation_logs |          3 | 72 kB
 prc_revisions      |          3 | 25 MB
```

Счётчики строк ключевых таблиц (`SELECT count(*) FROM …`):

```
prc_procurements = 17943   prc_revisions = 17942   prc_events = 18004   prc_fetches = 191
prc_documents = 66         prc_document_versions = 62                   prc_document_representations = 31
prc_items = 77             prc_financing = 26      prc_procurement_parties = 3
prc_estimates = 0          prc_estimate_items = 0  prc_ai_analyses = 0  llm_calls = 0
prc_operation_logs = 0     prc_regions = 0         cache = 0            cache_locks = 0
```

Разница с `findings/P4-load-baseline.md` M-7 (там `prc_revisions` 89 442, `prc_events` 89 444)
объясняется удалением данных первого прогона перед контрольным (D124) — числа выше относятся к
СЕГОДНЯШНЕМУ состоянию БД, а не к прогону P4.7.

---

## 2. Чеклист `rag-2026-07-31.md` §8 — сырые выводы по каждому пункту

### 2.1 P0 — FK-колонка без индекса

```sql
WITH fk AS (
  SELECT c.oid, c.conname, c.conrelid, c.conkey, c.confrelid, c.confdeltype,
         c.conrelid::regclass::text AS tbl,
         (SELECT string_agg(a.attname, ',' ORDER BY x.ord)
            FROM unnest(c.conkey) WITH ORDINALITY AS x(attnum, ord)
            JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=x.attnum) AS cols
  FROM pg_constraint c
  JOIN pg_class rel ON rel.oid=c.conrelid
  JOIN pg_namespace n ON n.oid=rel.relnamespace AND n.nspname='public'
  LEFT JOIN pg_inherits inh ON inh.inhrelid=c.conrelid
  WHERE c.contype='f' AND inh.inhrelid IS NULL
)
SELECT fk.tbl, fk.cols, fk.confrelid::regclass::text AS refs, fk.confdeltype
FROM fk
WHERE NOT EXISTS (
  SELECT 1 FROM pg_index i
  WHERE i.indrelid=fk.conrelid AND i.indisvalid
    AND (string_to_array(i.indkey::text,' ')::smallint[])[1:cardinality(fk.conkey)] @> fk.conkey
    AND (string_to_array(i.indkey::text,' ')::smallint[])[1:cardinality(fk.conkey)] <@ fk.conkey
) ORDER BY 1,2;
```

```
            tbl            |        cols         |         refs          | confdeltype
---------------------------+---------------------+-----------------------+-------------
 model_has_roles           | role_id             | roles                 | c
 model_has_scopes          | role_id             | roles                 | n
 prc_ai_analyses           | parent_analysis_id  | prc_ai_analyses       | n
 prc_ai_analyses           | reviewed_by         | users                 | n
 prc_ai_analysis_runs      | initiated_by        | users                 | n
 prc_ai_corrections        | created_by          | users                 | a
 prc_ai_corrections        | estimate_id         | prc_estimates         | n
 prc_ai_dataset_examples   | analysis_id         | prc_ai_analyses       | n
 prc_ai_dataset_examples   | fixture_id          | prc_ai_fixtures       | n
 prc_ai_dataset_examples   | verified_by         | users                 | n
 prc_ai_fixtures           | verified_by         | users                 | n
 prc_ai_prompt_suggestions | analysis_id         | prc_ai_analyses       | n
 prc_ai_prompt_suggestions | decided_by          | users                 | n
 prc_ai_prompt_suggestions | eval_run_id         | prc_ai_eval_runs      | n
 prc_ai_prompt_suggestions | fixture_id          | prc_ai_fixtures       | n
 prc_cancellations         | document_version_id | prc_document_versions | n
 prc_clarifications        | document_version_id | prc_document_versions | n
 prc_document_versions     | parent_version_id   | prc_document_versions | n
 prc_estimates             | verified_by         | users                 | n
 prc_financing             | kbk_ref_id          | ref_kbk               | n
 prc_items                 | ktru_ref_id         | ref_ktru              | n
 prc_items                 | okpd2_ref_id        | ref_okpd2             | n
 prc_procurement_parties   | organization_id     | org_organizations     | c
 prc_protocols             | document_version_id | prc_document_versions | n
 prc_regions               | platform_id         | prc_platforms         | c
(25 rows)
```

**25 из 86 FK не покрыты индексом** по ведущим колонкам.

### 2.2 P0 — семантика `ON DELETE` (`pg_constraint.confdeltype`)

```sql
SELECT c.confdeltype, count(*) FROM pg_constraint c
JOIN pg_class rel ON rel.oid=c.conrelid JOIN pg_namespace n ON n.oid=rel.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits inh ON inh.inhrelid=c.conrelid
WHERE c.contype='f' AND inh.inhrelid IS NULL GROUP BY 1 ORDER BY 1;
```

```
 confdeltype | count
-------------+-------
 a           |    28   -- NO ACTION (дефолт, правило не объявлено)
 c           |    24   -- CASCADE
 n           |    34   -- SET NULL
```

`r` (RESTRICT) и `d` (SET DEFAULT) — **0 вхождений**. 28 связей с `NO ACTION` (полный список — тот же
запрос с `WHERE c.confdeltype='a'`):

```
 llm_calls.analysis_id → prc_ai_analyses            prc_estimates.analysis_id → prc_ai_analyses
 llm_routes.provider_id → llm_providers             prc_estimates.document_version_id → prc_document_versions
 prc_ai_analyses.analysis_run_id → …analysis_runs   prc_estimates.platform_id → prc_platforms
 prc_ai_analyses.document_version_id → …versions    prc_estimates.procurement_id → prc_procurements
 prc_ai_analysis_runs.document_version_id → …       prc_events.procurement_id → prc_procurements
 prc_ai_corrections.created_by → users              prc_fetches.crawler_run_id → prc_crawler_runs
 prc_ai_dataset_examples.document_version_id → …    prc_fetches.proxy_id → prc_proxies
 prc_ai_eval_results.dataset_example_id → …         prc_ignored_items.registry_id → prc_registries
 prc_ai_eval_results.eval_run_id → …eval_runs       prc_ignore_rules.registry_id → prc_registries
 prc_crawler_runs.crawler_id → prc_crawlers         prc_procurements.registry_id → prc_registries
 prc_crawlers.registry_id → prc_registries          prc_proxy_checks.proxy_id → prc_proxies
 prc_daily_stats.crawler_id → prc_crawlers          prc_revisions.procurement_id → prc_procurements
 prc_documents.platform_id → prc_platforms          prc_document_versions.document_id → prc_documents
 prc_documents.procurement_id → prc_procurements    prc_eis_details.procurement_id → prc_procurements
```

Раскладка правил у детей ОДНОГО родителя `prc_procurements`:

```sql
SELECT c.conrelid::regclass::text AS child, c.confdeltype,
       (SELECT string_agg(a.attname,',') FROM unnest(c.conkey) x(n)
          JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=x.n) AS cols
FROM pg_constraint c WHERE c.contype='f' AND c.confrelid='prc_procurements'::regclass ORDER BY 1;
```

```
 CASCADE (9): prc_awards, prc_bids, prc_cancellations, prc_clarifications, prc_financing,
              prc_items, prc_lots, prc_procurement_parties, prc_protocols
 NO ACTION (5): prc_documents, prc_eis_details, prc_estimates, prc_events, prc_revisions
                (+ по 3 партиции prc_events / prc_revisions — унаследованные строки того же FK)
```

### 2.3 P0 — разъезд точностей `numeric`

```sql
SELECT column_name, count(DISTINCT (numeric_precision, numeric_scale)) AS variants,
       string_agg(DISTINCT table_name||' '||numeric_precision||','||numeric_scale, ' | ') AS where_
FROM information_schema.columns
WHERE table_schema='public' AND data_type='numeric'
GROUP BY column_name HAVING count(DISTINCT (numeric_precision, numeric_scale)) > 1 ORDER BY 1;
```

```
 column_name | variants |                          where_
-------------+----------+---------------------------------------------------------
 quantity    |        2 | prc_estimate_items 18,6 | prc_estimate_resources 18,6 | prc_items 15,3
 value       |        2 | prc_ai_eval_results 18,6 | pulse_aggregates 20,2
```

Полное распределение точностей (тот же источник, группировка по `(precision, scale)`):

```
  p | s | cols | список
----+---+------+---------------------------------------------------------------------------
  5 | 2 |    3 | prc_eis_details.bid_security_percent, …performance_security_percent,
    |   |      | prc_procurements.advance_percent
 15 | 3 |    1 | prc_items.quantity
 18 | 2 |   17 | prc_awards.final_price, prc_bids.price, prc_eis_details.bid_security_amount,
    |   |      | …performance_security_amount, prc_estimate_items.total_price_base/_curr,
    |   |      | …unit_price_base/_curr, prc_estimate_resources.price_base/_curr,
    |   |      | prc_estimate_totals.amount, prc_estimates.total_amount, prc_financing.amount,
    |   |      | prc_items.total_cost, prc_items.unit_price, prc_lots.initial_price,
    |   |      | prc_procurements.initial_price
 18 | 6 |   14 | prc_ai_eval_results.value, prc_estimate_items.index_value/quantity/unit_multiplier,
    |   |      | prc_estimate_resources.index_value/quantity/unit_multiplier,
    |   |      | prc_estimate_totals.rate, prc_estimate_work_types.overhead_rate(_curr),
    |   |      | …profit_rate(_curr), prc_estimates.labor_hours_machinists/_workers
 20 | 2 |    1 | pulse_aggregates.value  (вендорная таблица laravel/pulse)
```

Плавающая точка в схеме — **1 колонка** (не 0, как в §13.3 разведки: та снималась grep'ом по
миграциям на дереве `09e07a5`, а колонку завела миграция `2026_07_31_100062_create_prc_proxy_targets_table`
фазы P1):

```sql
SELECT table_name, column_name, data_type, is_nullable FROM information_schema.columns
WHERE table_schema='public' AND data_type IN ('real','double precision');
```

```
    table_name     |  column_name  |    data_type     | is_nullable
-------------------+---------------+------------------+-------------
 prc_proxy_targets | success_ratio | double precision | NO
```

(`database/migrations/2026_07_31_100062_create_prc_proxy_targets_table.php:28` —
`$table->double('success_ratio')->default(1.0)`, EWMA успешности узла.)

### 2.4 P1 — миграционная безопасность (invalid-индексы, блокирующие `ALTER`)

```sql
SELECT indexrelid::regclass AS idx, indrelid::regclass AS tbl, indisvalid, indisready
FROM pg_index WHERE NOT indisvalid OR NOT indisready;
```

```
(0 rows)
```

Таблицы, на которых блокирующая операция ощутима (heap > 1 МБ): `prc_procurements` (heap 13 МБ,
индексы 4760 кБ), `prc_revisions` (25 МБ суммарно по трём партициям), `prc_events` (3168 кБ),
`pulse_aggregates` (2944 кБ). Остальные 66 логических таблиц — < 1 МБ.

### 2.5 P1 — ограничения RANGE-партиционирования

```sql
SELECT c.relname AS parent, pg_get_partkeydef(c.oid) AS partkey,
       (SELECT string_agg(a.attname, ',' ORDER BY x.ord) FROM pg_constraint pk,
          unnest(pk.conkey) WITH ORDINALITY x(attnum,ord)
          JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum=x.attnum
        WHERE pk.conrelid=c.oid AND pk.contype='p') AS pk_cols,
       (SELECT count(*) FROM pg_inherits i WHERE i.inhparent=c.oid) AS partitions
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace AND n.nspname='public'
WHERE c.relkind='p' ORDER BY 1;
```

```
       parent       |       partkey       |    pk_cols     | partitions
--------------------+---------------------+----------------+------------
 llm_calls          | RANGE (called_at)   | id,called_at   |          3
 prc_events         | RANGE (occurred_at) | id,occurred_at |          3
 prc_fetches        | RANGE (fetched_at)  | id,fetched_at  |          3
 prc_operation_logs | RANGE (created_at)  | id,created_at  |          3
 prc_revisions      | RANGE (detected_at) | id,detected_at |          3
```

**PK ⊇ ключ партиционирования — выполняется у 5 из 5.**

```sql
SELECT count(*) AS fk_to_partitioned FROM pg_constraint c
JOIN pg_class p ON p.oid=c.confrelid WHERE c.contype='f' AND p.relkind='p';
```

```
 fk_to_partitioned
-------------------
                 0
```

Границы партиций и наличие DEFAULT:

```sql
SELECT p.relname AS parent, c.relname AS partition, pg_get_expr(c.relpartbound, c.oid) AS bounds
FROM pg_inherits i JOIN pg_class c ON c.oid=i.inhrelid JOIN pg_class p ON p.oid=i.inhparent
WHERE p.relkind='p' ORDER BY 1,2;

SELECT p.relname AS parent,
       count(*) FILTER (WHERE pg_get_expr(c.relpartbound, c.oid) = 'DEFAULT') AS default_partitions
FROM pg_inherits i JOIN pg_class c ON c.oid=i.inhrelid JOIN pg_class p ON p.oid=i.inhparent
WHERE p.relkind='p' GROUP BY 1 ORDER BY 1;
```

```
Все пять таблиц: партиции 2026_08 / 2026_09 / 2026_10, границы
  FROM ('2026-08-01 00:00:00+00') TO ('2026-11-01 00:00:00+00') — сплошное покрытие ТРЁХ месяцев.
default_partitions: llm_calls 0 · prc_events 0 · prc_fetches 0 · prc_operation_logs 0 · prc_revisions 0
```

Фактический диапазон данных в ключевых колонках:

```sql
SELECT 'prc_events' t, min(occurred_at), max(occurred_at), count(*) FROM prc_events
UNION ALL SELECT 'prc_revisions', min(detected_at), max(detected_at), count(*) FROM prc_revisions
UNION ALL SELECT 'prc_fetches',  min(fetched_at),  max(fetched_at),  count(*) FROM prc_fetches
UNION ALL SELECT 'llm_calls',    min(called_at),   max(called_at),   count(*) FROM llm_calls
UNION ALL SELECT 'prc_operation_logs', min(created_at), max(created_at), count(*) FROM prc_operation_logs;
```

```
         t          |          min           |          max           | count
--------------------+------------------------+------------------------+-------
 prc_events         | 2026-08-05 05:14:44+00 | 2026-08-05 13:48:22+00 | 18004
 prc_revisions      | 2026-08-05 11:56:54+00 | 2026-08-05 12:24:32+00 | 17942
 prc_fetches        | 2026-08-05 11:56:56+00 | 2026-08-05 12:25:27+00 |   191
 llm_calls          |                        |                        |     0
 prc_operation_logs |                        |                        |     0
```

### 2.6 P2 — `NOT NULL` и дефолты

Агрегат — §1.3: 481 nullable / 384 NOT NULL из 865; **`nullable` + `DEFAULT` = 0** (антипаттерна
«`DEFAULT 0` везде подряд на nullable-колонке» в схеме нет); 117 `NOT NULL` + `DEFAULT`.

Профиль заполненности `prc_procurements` на 17 943 боевых строках (сгенерированный
`count(<колонка>)` по всем nullable-колонкам таблицы):

```
etp_number                   0        detail_url                     0
ikz                          0        detail_fetched_at              8
procedure_type           17909        detail_last_error              9
procedure_type_raw       17943        detail_next_retry_at           8
customer_org_id              1        registry_guid                  0
initial_price            17943        ignored_at                     0
currency                 17943        ignore_reason                  0
advance_percent              0        attributes                 17943
okpd2_code                   0        created_at                 17943
okved_code                   0        updated_at                 17943
okved_additional             0        okpd2_codes                    0
region_code                  0        current_revision_id        17942
bidding_starts_at            0        current_revision_detected_at 17942
bidding_ends_at              0        summary_at                     0
```

(`0` = колонка `NULL` во всех 17 943 строках; `17943` = заполнена во всех.)

### 2.7 P2 — `jsonb` против выделенных колонок

```sql
SELECT count(*) FROM information_schema.columns c JOIN pg_class pc ON pc.relname=c.table_name
JOIN pg_namespace n ON n.oid=pc.relnamespace AND n.nspname='public'
LEFT JOIN pg_inherits i ON i.inhrelid=pc.oid
WHERE c.table_schema='public' AND c.data_type='jsonb' AND i.inhrelid IS NULL;
```

```
42
```

Из них **девять** — generic-«мешок» `attributes` (не семь, как в §13.3 разведки):

```sql
SELECT table_name FROM information_schema.columns
WHERE table_schema='public' AND data_type='jsonb' AND column_name='attributes' ORDER BY 1;
```

```
 org_organizations · prc_estimate_items · prc_lots · prc_procurement_parties · prc_procurements
 ref_kbk · ref_ktru · ref_okpd2 · ref_okved2
```

`->json(` в схеме — 0 (тип везде `jsonb`).

Фактические предикаты по `jsonb` в коде (`grep -rn "whereJsonContains\|->>'" app/ --include='*.php'`):

```
app/Console/Commands/Procurement/PipelineStatus.php:142  ->whereRaw("(result->>'needs_review')::boolean = true")
app/Console/Commands/Procurement/PipelineStuckCheck.php:168 ->whereRaw("result->>'branch_reason' = ?", ['unknown'])
app/Repositories/Procurement/Ai/FixtureStoreRepository.php:81/83 ->orderByRaw("type_profile->>'form'")
app/Services/Procurement/Proxy/ProxyResolver.php:166     ->whereJsonContains('prc_proxies.scopes', $scope->value)
```

GIN и выражающие индексы в схеме:

```sql
SELECT indexdef FROM pg_indexes WHERE schemaname='public'
  AND (indexdef LIKE '%gin%' OR indexdef LIKE '%((%');
```

```
CREATE INDEX prc_procurements_okpd2_codes_gin ON public.prc_procurements USING gin (okpd2_codes)
CREATE UNIQUE INDEX prc_ai_analyses_active_version_step_unique ON public.prc_ai_analyses
  USING btree (document_version_id, step) WHERE ((superseded_at IS NULL) AND (is_shadow = false))
CREATE INDEX prc_proxies_scopes_gin ON public.prc_proxies USING gin (scopes)
```

---

## 3. Индексы: фактическое использование и сверка с запросами кода

**Оговорка окна (Implementation Rules п.6).** `SELECT stats_reset FROM pg_stat_database WHERE
datname='agelto'` → **пусто (NULL)**: счётчики не сбрасывались ни разу. «0 сканов» читается как
«за всю жизнь этой БД», а не «за прогон». Нагрузка, которую БД видела, — смоук-прогон P4.7,
контрольный прогон P4.15 и точечные пробы P4.16, ВСЕ в archive-режиме кроулинга; контур ВЫДАЧИ
(API/витрина) не исполнялся ни разу, потому что его в проекте нет.

`prc_procurements` — все 15 индексов:

```sql
SELECT s.indexrelname, s.idx_scan, pg_size_pretty(pg_relation_size(s.indexrelid)) sz
FROM pg_stat_user_indexes s WHERE s.relname='prc_procurements' ORDER BY s.idx_scan DESC;
```

```
                      indexrelname                      | idx_scan |   sz
--------------------------------------------------------+----------+---------
 prc_procurements_pkey                                  |   699212 | 448 kB
 prc_procurements_registry_id_registry_number_unique    |   357487 | 1168 kB
 prc_procurements_registry_id_status_published_at_index |     7447 | 240 kB
 prc_procurements_published_at_index                    |       10 | 224 kB
 prc_procurements_okpd2_code_index                      |        1 | 208 kB
 prc_procurements_source_updated_at_index               |        1 | 192 kB
 prc_procurements_registry_guid_index                   |        1 | 208 kB
 prc_procurements_uuid_unique                           |        0 | 624 kB
 prc_procurements_okved_code_index                      |        0 | 208 kB
 prc_procurements_okved_additional_index                |        0 | 208 kB
 prc_procurements_region_code_index                     |        0 | 208 kB
 prc_procurements_status_bidding_ends_at_index          |        0 | 232 kB
 prc_procurements_okpd2_codes_gin                       |        0 | 144 kB
 prc_procurements_ikz_index                             |        0 | 200 kB
 prc_procurements_customer_org_id_index                 |        0 | 224 kB
```

```
pg_relation_size('prc_procurements')  = 13 MB (heap)
pg_indexes_size('prc_procurements')   = 4760 kB
```

Восемь индексов с `idx_scan = 0` занимают **2048 кБ из 4760 кБ** (сумма колонки `sz` восьми
нижних строк) — 43 % индексного объёма самой горячей на запись таблицы.

Сверка с запросами кода (`grep -rn "<колонка>" app/ --include='*.php'`, ручной осмотр найденных
файлов):

| Колонка индекса | Читатель в коде | Комментарий факта |
|:--|:--|:--|
| `uuid` | `ProcurementsTable.php:16` — `TextColumn::make('uuid')->searchable()` | Filament-поиск строит `ILIKE %…%`; btree-unique им не используется |
| `bidding_ends_at` | `ProcurementsTable.php:31` — `->sortable()` | индекс составной `(status, bidding_ends_at)`, ведущая колонка `status`; сортировка по одной `bidding_ends_at` его не берёт |
| `customer_org_id` | вхождений в `where`/`orderBy` — нет (только запись в `ProcurementStoreRepository`, DTO и инфолист) | читателя-запроса нет |
| `okved_code`, `okved_additional`, `region_code`, `ikz`, `okpd2_codes` | вхождений в `where`/`orderBy` — нет | читателя-запроса нет |

Прочие индексы с `idx_scan = 0` на НЕпустых таблицах (полный список — запрос
`pg_stat_user_indexes s JOIN pg_stat_user_tables t … WHERE s.idx_scan=0 AND t.n_live_tup>0`,
48 строк): `pulse_*` (вендорные, 7 индексов), `prc_document_versions_*` (2), `prc_documents_*` (2),
`prc_items_*` (2), `prc_financing_*` (2), `llm_routes_*` (2), `org_organizations_*` (4),
`prc_proxies_scopes_gin`, `migrations_pkey`, `users_*` (2), `failed_jobs_*` (2) и т. д. — все на
таблицах ≤ 600 строк.

---

## 4. Партиционирование и retention (пять таблиц)

```sql
SELECT parent_table, partition_interval, premake, retention, retention_keep_table, template_table
FROM partman.part_config ORDER BY 1;
```

```
       parent_table        | partition_interval | premake | retention | retention_keep_table |               template_table
---------------------------+--------------------+---------+-----------+----------------------+--------------------------------------------
 public.ai_llm_calls       | 1 mon              |       3 |           | t                    | partman.template_public_ai_llm_calls
 public.crw_fetches        | 1 mon              |       3 |           | t                    | partman.template_public_crw_fetches
 public.llm_calls          | 1 mon              |       3 | 12 months | f                    | partman.template_public_llm_calls
 public.prc_events         | 1 mon              |       3 | 6 months  | f                    | partman.template_public_prc_events
 public.prc_fetches        | 1 mon              |       3 | 6 months  | f                    | partman.template_public_prc_fetches
 public.prc_operation_logs | 1 mon              |       3 | 6 months  | f                    | partman.template_public_prc_operation_logs
 public.prc_revisions      | 1 mon              |       3 |           | t                    | partman.template_public_prc_revisions
 public.sys_operation_logs | 1 mon              |       3 |           | t                    | partman.template_public_sys_operation_logs
```

```sql
SELECT parent_table, to_regclass(parent_table) IS NOT NULL AS parent_exists FROM partman.part_config ORDER BY 1;
```

```
 public.ai_llm_calls       | f      ← родителя НЕТ
 public.crw_fetches        | f      ← родителя НЕТ
 public.sys_operation_logs | f      ← родителя НЕТ
 (остальные пять)          | t
```

Template-таблицы в схеме `partman` — **9**, из них 4 не соответствуют ни одной живой таблице:
`template_public_ai_llm_calls`, `template_public_crw_fetches`, `template_public_sys_operation_logs`
(привязаны к трём осиротевшим строкам `part_config`) и `template_public_prc_ai_llm_calls`
(не упомянута в `part_config` вовсе).

**Поведение обслуживания при осиротевшей строке — проба (read-only) и исходник функции.**

Проба:

```
docker exec agelto_postgres psql -U agelto -d agelto -c "SELECT partman.show_partitions('public.ai_llm_calls');"
```

```
ERROR:  Given parent table not found in system catalogs: public.ai_llm_calls
CONTEXT:  PL/pgSQL function show_partitions(text,text,boolean) line 52 at RAISE
```

Исходник (`SELECT prosrc FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace AND
n.nspname='partman' WHERE p.proname='run_maintenance'`, pg_partman 5.2.4). Единственная проверка
существования внутри цикла смотрит на строку `part_config`, а НЕ на таблицу:

```
v_parent_exists := NULL;
SELECT parent_table, time_encoder, time_decoder INTO v_parent_exists, …
  FROM partman.part_config WHERE parent_table = v_row.parent_table;
…
CONTINUE WHEN v_parent_exists IS NULL;
```

— у осиротевшей строки `part_config` она НА МЕСТЕ, поэтому `CONTINUE` не срабатывает, и ниже по
циклу вызывается `partman.show_partitions(v_row.parent_table, …)` (две точки в теле цикла), который
на несуществующей таблице бросает исключение выше. Внешний блок `EXCEPTION` функции завершается
`RAISE EXCEPTION '%'` — то есть падает ВЕСЬ вызов `run_maintenance()`, а не одна строка.

Порядок обхода — `ORDER BY maintenance_order ASC NULLS LAST, parent_table ASC` (там же в
`v_tables_list_sql`); `maintenance_order` не задан ни у одной строки, значит порядок алфавитный и
`public.ai_llm_calls` идёт ПЕРВЫМ.

Отпечаток этого в каталоге:

```sql
SELECT parent_table, maintenance_order, maintenance_last_run FROM partman.part_config
ORDER BY maintenance_order ASC NULLS LAST, parent_table ASC;
```

```
       parent_table        | maintenance_order |     maintenance_last_run
---------------------------+-------------------+-------------------------------
 public.ai_llm_calls       |                   | 2026-07-06 00:00:00.232087+00
 public.crw_fetches        |                   | 2026-07-06 00:00:00.235362+00
 public.llm_calls          |                   |            ← НИ РАЗУ
 public.prc_events         |                   | 2026-07-06 00:00:00.241263+00
 public.prc_fetches        |                   |            ← НИ РАЗУ
 public.prc_operation_logs |                   |            ← НИ РАЗУ
 public.prc_revisions      |                   | 2026-07-06 00:00:00.244524+00
 public.sys_operation_logs |                   | 2026-07-06 00:00:00.245981+00
```

Три АКТУАЛЬНЫЕ таблицы (`llm_calls`, `prc_fetches`, `prc_operation_logs`) не обслуживались ни разу;
пять отметок 2026-07-06 принадлежат прошлому поколению имён, когда осиротевших строк ещё не было.

Обработка ошибки в команде-обёртке (`app/Console/Commands/MaintainPartitions.php:38-52`):

```php
if ($this->pgPartmanIsAvailable()) {
    DB::statement('SELECT partman.run_maintenance()');
    …
    return self::SUCCESS;
}
// fallback-путь ниже
```

— fallback включается ТОЛЬКО при отсутствии расширения (`pgPartmanIsAvailable()` проверяет
`pg_extension`), а не при неуспехе `run_maintenance()`; исключение уходит наружу.

Состояние тестовой БД (`docker exec agelto_postgres psql -U agelto -d agelto_test -c
"SELECT parent_table, to_regclass(parent_table) IS NOT NULL … FROM partman.part_config"`):

```
 public.llm_calls | t | 12 months     public.prc_fetches        | t | 6 months
 public.prc_events| t | 6 months      public.prc_operation_logs | t | 6 months
 public.prc_revisions | t | (NULL)
```

— **пять строк, осиротевших нет**: дрейф каталога `partman` существует только в БД `agelto`.

Autovacuum-параметры горячих партиций:

```sql
SELECT n.nspname||'.'||c.relname AS rel, c.reloptions FROM pg_class c
JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE c.reloptions IS NOT NULL AND n.nspname IN ('public','partman') ORDER BY 1;
```

```
 partman.template_public_llm_calls        | {autovacuum_vacuum_scale_factor=0.01,autovacuum_analyze_scale_factor=0.01,autovacuum_vacuum_insert_scale_factor=0.01}
 partman.template_public_prc_ai_llm_calls | {то же}
 partman.template_public_prc_fetches      | {то же}
 public.llm_calls_2026_08 / _09 / _10     | {то же}
 public.prc_fetches_2026_08 / _09 / _10   | {то же}
```

— тюнинг покрывает `llm_calls` (0 строк) и `prc_fetches` (191 строка); `prc_revisions`
(17 942 строки / 25 МБ) и `prc_events` (18 004 / 3168 кБ) идут на дефолтах Postgres
(`autovacuum_vacuum_scale_factor` 0.2, `autovacuum_analyze_scale_factor` 0.1).

### 4.1 `prc_revisions` — поимённый вердикт (мандат Deliverables, D74)

**Вердикт (зафиксирован D74, ревизией не переоткрывается): `retention = NULL` — ПОДТВЕРЖДЁННОЕ
РЕШЕНИЕ (V2-CORE D41), не находка.** Класс таблицы по модели D72 — «состояние», retention предписан
только классу «поток». В перечень находок не входит, в P7.5 не уходит; изменение потребовало бы
правки живого гейт-теста `tests/Feature/Infra/PartitionRetentionTest.php` (три утверждения) и
`config/partitioning.php:80-84` — то есть отдельного решения владельца. Развёрнуто —
`research/08-db-review.md` §10.1.

`prc_revisions` — **мандатная строка-наблюдение D74**: `retention = NULL`, `retention_keep_table = t`;
**3 партиции**, суммарный размер **25 МБ**, **17 942 строки** (команды —
`SELECT count(*) FROM pg_inherits WHERE inhparent='prc_revisions'::regclass`,
`SELECT pg_size_pretty(sum(pg_total_relation_size(c.oid))) FROM pg_inherits i JOIN pg_class c ON
c.oid=i.inhrelid WHERE i.inhparent='prc_revisions'::regclass`, `SELECT count(*) FROM prc_revisions`;
время снятия — 2026-08-05 14:33 UTC, якорь `6ddaa62`).

---

## 5. Рантайм кластера и наблюдаемость (§13.7 — собственная сверка)

```sql
SELECT name, setting, unit, source FROM pg_settings WHERE name IN (…);
```

```
                name                 | setting | unit |       source
-------------------------------------+---------+------+--------------------
 autovacuum                          | on      |      | default
 checkpoint_timeout                  | 300     | s    | default
 effective_cache_size                | 524288  | 8kB  | default
 idle_in_transaction_session_timeout | 0       | ms   | default
 log_min_duration_statement          | -1      | ms   | default
 maintenance_work_mem                | 65536   | kB   | default
 max_connections                     | 100     |      | configuration file
 max_parallel_workers                | 8       |      | default
 max_wal_size                        | 1024    | MB   | configuration file
 random_page_cost                    | 4       |      | default
 shared_buffers                      | 16384   | 8kB  | configuration file
 shared_preload_libraries            |         |      | default
 statement_timeout                   | 0       | ms   | default
 track_counts                        | on      |      | default
 wal_level                           | replica |      | default
 work_mem                            | 4096    | kB   | default
```

`shared_buffers` = 128 МБ, `work_mem` = 4 МБ, `maintenance_work_mem` = 64 МБ — все значения
`postgresql.conf` образа, ни один параметр не задан проектом (`source` нигде не `command line`).
`shared_preload_libraries` — пусто.

```sql
SELECT extname, extversion FROM pg_extension ORDER BY 1;
```

```
 pg_partman | 5.2.4
 plpgsql    | 1.0
```

— `pg_stat_statements` и `pgstattuple` отсутствуют; **строгий bloat не измерен** (доля мёртвых
строк `n_dead_tup/(live+dead)` — не bloat, `rag-2026-07-31.md` §7).

```
grep -n "shm_size" docker-compose.yml docker-compose.prod.yml
  docker-compose.prod.yml:82:    shm_size: '2gb'      ← внутри блока horizon: (строки 68–90)
  (у сервиса postgres:, строка 136 и ниже, shm_size отсутствует; в docker-compose.yml — 0 вхождений)

grep -n "CACHE_STORE" docker-compose.yml docker-compose.prod.yml config/cache.php
  docker-compose.yml:42        CACHE_STORE: ${CACHE_STORE:-redis}
  docker-compose.prod.yml:35   CACHE_STORE: ${CACHE_STORE:-redis}
  config/cache.php:18          'default' => env('CACHE_STORE', 'database')
```

Эффективное значение в живых рантаймах:

```
docker exec agelto_app     php artisan config:show cache.default → redis
docker exec agelto_horizon php artisan config:show cache.default → redis
```

Таблица `cache`: **0 строк, 24 кБ**; `cache_locks`: **0 строк, 24 кБ**
(`SELECT count(*) FROM cache`, `pg_total_relation_size('cache')`).

**Поимённый вердикт по таблице `cache` (мандат Deliverables): ОСТАВИТЬ — удаление миграцией
сейчас запрещено.** Fallback В КОДЕ (`config/cache.php:18`) всё ещё `database`, поэтому любой
контекст без переменной окружения (artisan с хоста, CI, контейнер без compose-блока) начнёт писать
в неё; удалив таблицу раньше смены fallback, мы меняем «лишняя запись в Postgres» на «фатальная
ошибка кэша». Порядок: сначала P7.4 переводит fallback на `redis` (D75, рычаг «а») и предъявляет
доказательство ненаписания, и только ПОСЛЕ этого удаление становится обсуждаемым — отдельным
решением владельца, не находкой P7.5. Развёрнуто — `research/08-db-review.md` §11.1.

Активных соединений на момент снятия: `SELECT count(*) FROM pg_stat_activity WHERE
datname='agelto'` → **11** при `max_connections = 100`.

---

## 6. Что НЕ снималось и почему

| Не снято | Почему |
|:--|:--|
| Строгий bloat (`pgstattuple`) | расширение не установлено; введение расширений — Scope P7.4, не этого item'а |
| Per-query write pressure / contention (`pg_stat_statements`) | `shared_preload_libraries` пуст, требуется рестарт кластера — Scope P7.4 |
| Replica lag | реплики нет; на одиночном primary не измеряется в принципе |
| WAL-скорость и wait events «сейчас» | снимаются только ПОД нагрузкой дельтой/серией сэмплов; прогон не запускается этим item'ом (Scope Excluded), снятое на P4.7 — `findings/P4-load-baseline.md` M-3/M-4/M-5 |
| `idx_scan` «за окно прогона» | `stats_reset` = NULL, счётчики не сбрасывались — доступно только «за всю жизнь БД» |
