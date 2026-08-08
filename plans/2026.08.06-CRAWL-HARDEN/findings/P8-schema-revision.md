# FINDING — P8.1: карта поглощения и решение по составу схемы

**Дата:** 2026-08-08 · **Дерево:** `6fcfb263` · **Item:** P8.1 · **Статус Q16:** утверждено
владельцем 2026-08-08 → D130.

## 1. Командный инвентарь миграций

Инвентарь снят из текущего рабочего дерева, а не перенесён из спеки:

```bash
ls database/migrations
ls database/migrations | grep -vc '_create_'
ls database/migrations | grep -Ec '_(add|drop|widen|declare|extend)_'
ls database/migrations | grep -Ec '_(setup|set|enable|precreate|prune)_'
```

Результат: **93** файла, из них **60** `create_*`, **27** догоняющих табличной формы и **6**
инфраструктурных операций. Всего не-`create_*` — **33**. Глагол `extend_` появился после авторинга
P8.1 и не подходит ни под один из двух старых regex; файл всё равно табличный по фактическому DDL и
включён в карту. Это ожидаемое следствие правила «снимать на момент исполнения».

### 1.1. Догоняющие миграции табличной формы — 27 строк

| # | Догоняющая миграция | Базовая create-миграция | Что поглощается | Судьба провенанса |
|--:|:--|:--|:--|:--|
| 1 | `2026_07_04_100048_add_procurement_foreign_to_prc_procurement_parties_table.php` | `2026_07_04_100010_create_prc_procurement_parties_table.php` | FK `procurement_id → prc_procurements.id`, `ON DELETE CASCADE` | отдельного докблока нет |
| 2 | `2026_07_08_100049_add_lease_columns_to_prc_documents_table.php` | `2026_07_04_100018_create_prc_documents_table.php` | `locked_by text nullable`, `locked_until timestamptz nullable` | отдельного докблока нет |
| 3 | `2026_07_27_100054_add_fixture_to_prc_ai_dataset_examples_table.php` | `2026_07_04_100030_create_prc_ai_dataset_examples_table.php` | `fixture_id` nullable, FK → `prc_ai_fixtures`, `ON DELETE SET NULL` | комментарий D6/D10 переносится к колонке |
| 4 | `2026_07_27_100056_drop_prepared_storage_path_from_prc_document_versions_table.php` | `2026_07_04_100019_create_prc_document_versions_table.php` | `prepared_storage_path` отсутствует в финальной форме | докблок D25/P3.1 переносится как объяснение отсутствия второго носителя |
| 5 | `2026_07_31_100060_add_analysis_id_to_prc_ai_dataset_examples_table.php` | `2026_07_04_100030_create_prc_ai_dataset_examples_table.php` | `analysis_id` nullable, FK → `prc_ai_analyses`, `ON DELETE SET NULL` | комментарий D56/P7.1 переносится к колонке |
| 6 | `2026_07_31_100061_add_routing_columns_to_prc_proxies_table.php` | `2026_07_04_100040_create_prc_proxies_table.php` | `country`, `scopes`, `open_until`, `open_count`, `last_failure_reason`, `last_success_at`, `last_failure_at`; удалить `targets`; btree `(is_active,country)`; GIN `scopes` | докблок D4/D7 и комментарии колонок переносятся целиком |
| 7 | `2026_07_31_100063_add_proxy_routing_to_prc_crawlers_table.php` | `2026_07_04_100038_create_prc_crawlers_table.php` | `use_proxy bool default true`, `proxy_country char(2) nullable` | докблок D80 переносится целиком |
| 8 | `2026_08_04_100064_add_regulatory_base_to_prc_estimates_table.php` | `2026_07_04_100033_create_prc_estimates_table.php` | `regulatory_base text nullable` | комментарий GAP-8/P5.3 переносится к колонке |
| 9 | `2026_08_04_100065_add_disk_to_prc_document_versions_table.php` | `2026_07_04_100019_create_prc_document_versions_table.php` | `disk text nullable` после `storage_path` | комментарий D11/D50/D114/P6.2 переносится к колонке |
| 10 | `2026_08_04_100066_add_disk_to_prc_ai_fixtures_table.php` | `2026_07_27_100053_create_prc_ai_fixtures_table.php` | `disk text nullable` после `storage_path` | комментарий D11/D50/D114/P6.2 переносится к колонке |
| 11 | `2026_08_04_100067_add_raw_result_disk_to_prc_estimates_table.php` | `2026_07_04_100033_create_prc_estimates_table.php` | `raw_result_disk text nullable` после `raw_result_ref` | комментарий D114/P6.2 переносится к колонке |
| 12 | `2026_08_05_100069_add_missing_fk_indexes.php` | 15 create-файлов; построчная карта в §2 | 23 btree-индекса ведущих FK; строка `prc_regions.platform_id` исчезает вместе со сносимой таблицей | общий докблок P7.5/F-01 переносится в затронутые create-файлы по контурам |
| 13 | `2026_08_05_100070_declare_explicit_on_delete_for_fk_no_action.php` | 21 create-файл; построчная карта в §3 | 23 явных `NO ACTION` + 5 явных `RESTRICT`; строки сносимых `prc_daily_stats`/`prc_ignore_rules` исчезают вместе с таблицами | докблок P7.5/F-02/D133 переносится в затронутые create-файлы по контурам |
| 14 | `2026_08_05_100071_widen_prc_items_quantity_precision.php` | `2026_07_04_100015_create_prc_items_table.php` | `quantity numeric(18,6)` вместо `(15,3)` | докблок P7.5/F-03 переносится к колонке |
| 15 | `2026_08_05_100072_add_crawler_run_foreign_to_prc_revisions_table.php` | `2026_07_04_100012_create_prc_revisions_table.php` | FK `crawler_run_id → prc_crawler_runs.id`, `ON DELETE NO ACTION`, без нового индекса | докблок P7.5/F-04 переносится к FK |
| 16 | `2026_08_05_100073_add_analysis_run_foreign_to_llm_calls_table.php` | `2026_07_04_100026_create_llm_calls_table.php` | FK `analysis_run_id → prc_ai_analysis_runs.id`, `ON DELETE NO ACTION`; индекс уже существует | докблок P7.5/F-05 переносится к FK |
| 17 | `2026_08_05_100075_add_result_expression_indexes_to_prc_ai_analyses_table.php` | `2026_07_04_100025_create_prc_ai_analyses_table.php` | expression indexes по `result->>'branch_reason'` и `((result->>'needs_review')::boolean)` | докблок P7.5/F-08 переносится к индексам |
| 18 | `2026_08_06_100077_add_quality_to_prc_estimate_items_table.php` | `2026_07_04_100035_create_prc_estimate_items_table.php` + `2026_07_04_100033_create_prc_estimates_table.php` | `quality jsonb nullable`; partial index `(estimate_id) WHERE quality IS NOT NULL`; `validation_notes jsonb nullable` | комментарий P8.4/D136/D155/D74 переносится в оба create-файла |
| 19 | `2026_08_07_100079_drop_okpd2_columns_from_prc_procurements.php` | `2026_07_04_100011_create_prc_procurements_table.php` | `okpd2_code`, `okpd2_codes` и их btree/GIN-индексы отсутствуют в финальной форме | докблок D16/P2.6 переносится как объяснение отсутствия мёртвой ветки |
| 20 | `2026_08_07_100080_add_run_control_to_prc_crawler_runs_table.php` | `2026_07_04_100039_create_prc_crawler_runs_table.php` | `stop_requested_at`, `stop_kind`, `stop_requested_by` nullable FK → users `SET NULL`, `queue_job_id` | отдельного докблока нет |
| 21 | `2026_08_07_100081_add_stop_policy_to_prc_crawlers_table.php` | `2026_07_04_100038_create_prc_crawlers_table.php` | `stop_policy text NOT NULL default 'default'` | докблок P3.3 переносится к колонке |
| 22 | `2026_08_08_100080_add_execution_lane_to_prc_crawlers_table.php` | `2026_07_04_100038_create_prc_crawlers_table.php` | `execution_lane text NOT NULL default 'standard'` | докблок P4.7/D117 переносится к колонке |
| 23 | `2026_08_08_100081_add_throttle_to_prc_platforms_table.php` | `2026_07_04_100000_create_prc_platforms_table.php` | `throttle jsonb nullable` | докблок P4.1/D36 переносится к колонке |
| 24 | `2026_08_08_100083_add_disabled_columns_to_prc_proxies_table.php` | `2026_07_04_100040_create_prc_proxies_table.php` | `disabled_at timestamptz nullable`, `disabled_reason text nullable` | докблок D41 переносится к колонкам |
| 25 | `2026_08_08_100084_extend_prc_fetches_for_all_stages.php` | `2026_07_04_100041_create_prc_fetches_table.php` | `crawler_run_id` становится nullable; `stage`, `procurement_id`, `wait_ms`; индекс и FK `procurement_id → prc_procurements.id`, `NO ACTION` | отдельного докблока нет; имя `extend_` явно сохранить в карте провенанса |
| 26 | `2026_08_08_100086_add_detail_parse_outcome_to_prc_procurements_table.php` | `2026_07_04_100011_create_prc_procurements_table.php` | `detail_parse_outcome jsonb nullable` | докблок про последний измеримый исход переносится к колонке |
| 27 | `2026_08_08_100087_add_succeeded_at_to_prc_procurements_table.php` | `2026_07_04_100011_create_prc_procurements_table.php` | `succeeded_at timestamptz nullable`; partial index `WHERE succeeded_at IS NOT NULL` | отдельного докблока нет |

### 1.2. Инфраструктурные операции — 6 строк

Эти файлы не поглощаются и не переименовываются: у них нет целевой create-таблицы, они меняют
кластер, pg_partman или набор физических партиций.

| # | Миграция | Операция | Судьба |
|--:|:--|:--|:--|
| 28 | `2026_07_04_100047_setup_pg_partman.php` | установка/регистрация пяти родителей в pg_partman, идемпотентная чистка старого имени | оставить отдельной; докблок обязателен |
| 29 | `2026_07_09_100050_set_pg_partman_retention.php` | retention четырёх эфемерных родителей | оставить отдельной; тест включает файл по пути |
| 30 | `2026_07_09_100051_set_hot_partition_autovacuum.php` | reloptions template-таблиц и существующих партиций | оставить отдельной |
| 31 | `2026_08_05_100068_enable_db_observability_extensions.php` | `pg_stat_statements` + `pgstattuple` | оставить отдельной |
| 32 | `2026_08_05_100074_precreate_historical_partitions.php` | 12 исторических месяцев для пяти родителей | оставить отдельной |
| 33 | `2026_08_05_100076_prune_orphaned_partman_part_config_entries.php` | чистка всех устаревших `public.*` записей partman | оставить отдельной |

## 2. `100069`: все 23 FK-индекса по строкам

Контур определяет item-получателя: закупочно-краулинговую часть поглощает P8.2, AI/LLM/сметную —
P8.3. Файл удаляется только после последней строки.

| Контур | Таблица | Колонка индекса | Create-миграция | Судьба |
|:--|:--|:--|:--|:--|
| AI | `prc_ai_analyses` | `parent_analysis_id` | `100025_create_prc_ai_analyses` | перенести |
| AI | `prc_ai_analyses` | `reviewed_by` | `100025_create_prc_ai_analyses` | перенести |
| AI | `prc_ai_analysis_runs` | `initiated_by` | `100059_create_prc_ai_analysis_runs` | перенести |
| AI | `prc_ai_corrections` | `created_by` | `100052_create_prc_ai_corrections` | перенести |
| AI | `prc_ai_corrections` | `estimate_id` | `100052_create_prc_ai_corrections` | перенести |
| AI | `prc_ai_dataset_examples` | `analysis_id` | `100030_create_prc_ai_dataset_examples` | перенести |
| AI | `prc_ai_dataset_examples` | `fixture_id` | `100030_create_prc_ai_dataset_examples` | перенести |
| AI | `prc_ai_dataset_examples` | `verified_by` | `100030_create_prc_ai_dataset_examples` | перенести |
| AI | `prc_ai_fixtures` | `verified_by` | `100053_create_prc_ai_fixtures` | перенести |
| AI | `prc_ai_prompt_suggestions` | `analysis_id` | `100058_create_prc_ai_prompt_suggestions` | перенести |
| AI | `prc_ai_prompt_suggestions` | `decided_by` | `100058_create_prc_ai_prompt_suggestions` | перенести |
| AI | `prc_ai_prompt_suggestions` | `eval_run_id` | `100058_create_prc_ai_prompt_suggestions` | перенести |
| AI | `prc_ai_prompt_suggestions` | `fixture_id` | `100058_create_prc_ai_prompt_suggestions` | перенести |
| AI | `prc_estimates` | `verified_by` | `100033_create_prc_estimates` | перенести |
| Закупочный | `prc_cancellations` | `document_version_id` | `100024_create_prc_cancellations` | перенести |
| Закупочный | `prc_clarifications` | `document_version_id` | `100023_create_prc_clarifications` | перенести |
| Закупочный | `prc_document_versions` | `parent_version_id` | `100019_create_prc_document_versions` | перенести |
| Закупочный | `prc_financing` | `kbk_ref_id` | `100016_create_prc_financing` | перенести |
| Закупочный | `prc_items` | `ktru_ref_id` | `100015_create_prc_items` | перенести |
| Закупочный | `prc_items` | `okpd2_ref_id` | `100015_create_prc_items` | перенести |
| Закупочный | `prc_procurement_parties` | `organization_id` | `100010_create_prc_procurement_parties` | перенести |
| Закупочный | `prc_protocols` | `document_version_id` | `100020_create_prc_protocols` | перенести |
| Снос D82 | `prc_regions` | `platform_id` | `100004_create_prc_regions` | не переносить: таблица удаляется |

Итого: **14 AI + 8 закупочных переносов + 1 строка сноса = 23**.

## 3. `100070`: все 28 пар «таблица, колонка, ON DELETE»

| Контур | Таблица.колонка → родитель | Поведение | Create-миграция / судьба |
|:--|:--|:--|:--|
| AI | `llm_calls.analysis_id → prc_ai_analyses` | `NO ACTION` | `100026_create_llm_calls` |
| AI | `llm_routes.provider_id → llm_providers` | `NO ACTION` | `100028_create_llm_routes` |
| AI | `prc_ai_analyses.analysis_run_id → prc_ai_analysis_runs` | `NO ACTION` | `100025_create_prc_ai_analyses` |
| AI | `prc_ai_analyses.document_version_id → prc_document_versions` | `NO ACTION` | `100025_create_prc_ai_analyses` |
| AI | `prc_ai_analysis_runs.document_version_id → prc_document_versions` | `NO ACTION` | `100059_create_prc_ai_analysis_runs` |
| AI | `prc_ai_corrections.created_by → users` | `NO ACTION` | `100052_create_prc_ai_corrections` |
| AI | `prc_ai_dataset_examples.document_version_id → prc_document_versions` | `NO ACTION` | `100030_create_prc_ai_dataset_examples` |
| AI | `prc_ai_eval_results.dataset_example_id → prc_ai_dataset_examples` | `NO ACTION` | `100032_create_prc_ai_eval_results` |
| AI | `prc_ai_eval_results.eval_run_id → prc_ai_eval_runs` | `NO ACTION` | `100032_create_prc_ai_eval_results` |
| AI | `prc_estimates.analysis_id → prc_ai_analyses` | `NO ACTION` | `100033_create_prc_estimates` |
| AI | `prc_estimates.document_version_id → prc_document_versions` | `NO ACTION` | `100033_create_prc_estimates` |
| AI | `prc_estimates.platform_id → prc_platforms` | `NO ACTION` | `100033_create_prc_estimates` |
| AI | `prc_estimates.procurement_id → prc_procurements` | `RESTRICT` | `100033_create_prc_estimates` |
| Закупочный | `prc_crawler_runs.crawler_id → prc_crawlers` | `NO ACTION` | `100039_create_prc_crawler_runs` |
| Закупочный | `prc_crawlers.registry_id → prc_registries` | `NO ACTION` | `100038_create_prc_crawlers` |
| Снос D82 | `prc_daily_stats.crawler_id → prc_crawlers` | `NO ACTION` | не переносить: таблица удаляется |
| Закупочный | `prc_documents.platform_id → prc_platforms` | `NO ACTION` | `100018_create_prc_documents` |
| Закупочный | `prc_document_versions.document_id → prc_documents` | `NO ACTION` | `100019_create_prc_document_versions` |
| Закупочный | `prc_fetches.crawler_run_id → prc_crawler_runs` | `NO ACTION` | `100041_create_prc_fetches` |
| Закупочный | `prc_fetches.proxy_id → prc_proxies` | `NO ACTION` | `100041_create_prc_fetches` |
| Закупочный | `prc_ignored_items.registry_id → prc_registries` | `NO ACTION` | `100044_create_prc_ignored_items` |
| Снос D82 | `prc_ignore_rules.registry_id → prc_registries` | `NO ACTION` | не переносить: таблица удаляется |
| Закупочный | `prc_procurements.registry_id → prc_registries` | `NO ACTION` | `100011_create_prc_procurements` |
| Закупочный | `prc_proxy_checks.proxy_id → prc_proxies` | `NO ACTION` | `100042_create_prc_proxy_checks` |
| Закупочный | `prc_documents.procurement_id → prc_procurements` | `RESTRICT` | `100018_create_prc_documents` |
| Закупочный | `prc_eis_details.procurement_id → prc_procurements` | `RESTRICT` | `100017_create_prc_eis_details` |
| Закупочный | `prc_events.procurement_id → prc_procurements` | `RESTRICT` | `100013_create_prc_events` |
| Закупочный | `prc_revisions.procurement_id → prc_procurements` | `RESTRICT` | `100012_create_prc_revisions` |

Итого: **23 `NO ACTION` + 5 `RESTRICT` = 28**; из них две строки исчезают со сносимыми таблицами.

## 4. Счётный снимок

`artifacts/P8-schema-baseline.json` снят после успешного
`migrate:fresh --seed --no-interaction` из текущего дерева. В `public` — **73** обычных или
партиционированных родительских таблицы; физические партиции исключены. Первая запись массива —
шапка с датой, HEAD, командой fresh и точным SQL; следующие 73 записи — `t/cols/idx/fks`.

Снимок учитывает текущую незакоммиченную миграцию `100087_add_succeeded_at...`, потому что правило
P8.1 требует схему именно текущего дерева. P8.1 её не меняет и не присваивает себе.

## 5. Состав сноса D82 и доказательство писателей

Поиск выполнен по классу модели с исключением `app/Models/**` и `database/factories/**`:

```bash
rg -n '\bDailyStat\b|\bRegion\b|\bIgnoreRule\b' app tests database/seeders \
  --glob '*.php' --glob '!app/Models/**' --glob '!database/factories/**'
```

| Таблица / класс | Факт вне модели и фабрики | Тянущиеся файлы и строки |
|:--|:--|:--|
| `prc_daily_stats` / `DailyStat` | совпадений нет: ни писателя, ни читателя | `app/Models/Procurement/Crawler/DailyStat.php`; `database/factories/Procurement/Crawler/DailyStatFactory.php`; create `100045`; строка `prc_daily_stats.crawler_id` в `100070` |
| `prc_regions` / `Region` | совпадений нет: ни писателя, ни читателя | `app/Models/Procurement/Platform/Region.php`; `database/factories/Procurement/Platform/RegionFactory.php`; create `100004`; импорт и индекс `Region.platform_id` в `100069` |
| `prc_ignore_rules` / `IgnoreRule` | доменного писателя/читателя нет; единственный writer — штатный generic CRUD Filament, но ни один сервис созданное правило не применяет | модель; фабрика; `app/Filament/Resources/Procurement/Crawler/IgnoreRules/**`; `app/Guards/Admin/Permissions/IgnoreRulePermission.php`; import + `...$this->crud(IgnoreRulePermission::class)` в `OperatorRole.php:13,46`; create `100043`; строка `prc_ignore_rules.registry_id` в `100070` |

Контроль живого соседа: `IgnoredItem` читается `CsvBlockIngestor.php:189` и покрыт
`CsvBlockIngestorTest.php:151`; `prc_ignored_items` не сносится.

## 6. Q16 — матрица для утверждения владельцем

Полная машинная матрица остаётся в `artifacts/eis-card-inventory.json`: 353 строки,
65 строк без носителя, 12 неясных, 57 рекомендаций «колонка», 284 `jsonb`, 12 заглушек.
Для решения ниже строки дедуплицированы по подписи.

### 6.1. Уже существующие колонки — новых колонок не требуют

21 уникальная подпись с рекомендацией «колонка» уже имеет носитель: `procedure_type_raw/type`,
`status/source_stage`, `region_code`, `initial_price`, `currency`, `ikz` и его разбор,
`title`, `bidding_ends_at`, `prc_items.classifier_code`, `prc_events.occurred_at`, а также шесть
категорий в уже существующей `prc_documents.kind`. Сработавшие признаки — доменное правило для
всех, кроме `initial_price` (фильтр/сортировка), и дополнительно наблюдение 6/6 для procedure type,
status и region. Единственное действие — начать заполнять существующую `prc_documents.kind`; это
не schema-change и не создаёт колонку.

### 6.2. Кандидаты без надёжного носителя — 55 уникальных подписей

Для **каждой** строки ниже три признака имеют одно значение: фильтр/сортировка — **нет**;
обязательность у всех шести видов — **нет**; доменное правило — **нет**. Рекомендация каждой
строки — `prc_procurements.attributes` (jsonb), новых колонок нет.

| Поле инвентаря | Текущий носитель | Ф/С | 6/6 | Домен | Рекомендация |
|:--|:--|:--:|:--:|:--:|:--|
| БИК ТОФК | нет | — | — | — | `attributes` |
| Вид бюджета | нет | — | — | — | `attributes` |
| Виды работ в соответствии с ч.1 ст. 166 Жилищного кодекса | нет | — | — | — | `attributes` |
| Дата окончания срока рассмотрения заявок на участие в предварительном отборе | нет | — | — | — | `attributes` |
| Дата окончания срока рассмотрения заявок на участие в электронном аукционе | нет | — | — | — | `attributes` |
| Дата окончания срока рассмотрения и оценки вторых частей заявок | нет | — | — | — | `attributes` |
| Дата проведения процедуры подачи предложений о цене контракта либо о сумме цен единиц | нет | — | — | — | `attributes` |
| Дата проведения электронного аукциона | нет | — | — | — | `attributes` |
| Детализирующие показатели | нет | — | — | — | `attributes` |
| Дополнительная информация | нет | — | — | — | `attributes` |
| Дополнительная информация о содержании и порядке оценки по показателю | нет | — | — | — | `attributes` |
| Дополнительные требования | неясно: сейчас общий `participant_requirements` jsonb | — | — | — | `attributes` |
| Закупка за счет бюджетных средств | неясно: соответствие `prc_financing.kind` не доказано | — | — | — | `attributes` |
| Закупка за счет собственных средств организации | неясно: соответствие `prc_financing.kind` не доказано | — | — | — | `attributes` |
| Значимость показателя, % | нет | — | — | — | `attributes` |
| Значимость детализирующего показателя, % | нет | — | — | — | `attributes` |
| Значимость критерия, % | нет | — | — | — | `attributes` |
| Информация о требованиях к гарантийному обслуживанию товара | нет | — | — | — | `attributes` |
| Код территории муниципального образования | нет | — | — | — | `attributes` |
| Место выполнения работ и (или) оказания услуг | нет | — | — | — | `attributes` |
| Место нахождения | неясно: `address_raw` не различает второй адрес | — | — | — | `attributes` |
| Место поставки товара | нет | — | — | — | `attributes` |
| Место поставки товара, выполнения работы или оказания услуги | нет | — | — | — | `attributes` |
| Наименование бюджета | нет | — | — | — | `attributes` |
| Наименование критерия | нет | — | — | — | `attributes` |
| Наименование показателя | нет | — | — | — | `attributes` |
| Наименование детализирующего показателя | нет | — | — | — | `attributes` |
| Номер единого казначейского счета | нет | — | — | — | `attributes` |
| Номер казначейского счета | нет | — | — | — | `attributes` |
| Номер типовых условий контракта | нет | — | — | — | `attributes` |
| Период действия результатов предварительного отбора | нет | — | — | — | `attributes` |
| Платежные реквизиты для обеспечения гарантийных обязательств | нет | — | — | — | `attributes` |
| Платежные реквизиты для обеспечения исполнения контракта | нет | — | — | — | `attributes` |
| Показатели критерия оценки заявок | нет | — | — | — | `attributes` |
| Получатель | нет | — | — | — | `attributes` |
| Порядок внесения денежных средств в качестве обеспечения заявки, а также условия гарантии | нет | — | — | — | `attributes` |
| Порядок оценки по показателю | нет | — | — | — | `attributes` |
| Порядок предоставления обеспечения гарантийных обязательств, требования к обеспечению | нет | — | — | — | `attributes` |
| Порядок предоставления обеспечения исполнения контракта, требования к обеспечению | нет | — | — | — | `attributes` |
| Право заключения контрактов с несколькими участниками (ч.10 ст.34 44-ФЗ) | нет | — | — | — | `attributes` |
| Предельное максимальное значение показателя характеристики | нет | — | — | — | `attributes` |
| Предмет электронного аукциона | неясно: второй кандидат на `title` у 615-ПП | — | — | — | `attributes` |
| Размер обеспечения гарантийных обязательств | нет | — | — | — | `attributes` |
| Реквизиты счета по ч.13 ст.44 44-ФЗ | нет | — | — | — | `attributes` |
| Реквизиты счета для учета операций со средствами, поступающими заказчику | нет | — | — | — | `attributes` |
| Сведения о НПА субъекта РФ о дополнительных видах капремонта | нет | — | — | — | `attributes` |
| Срок поставки товара (календарных дней) | нет | — | — | — | `attributes` |
| Срок гарантии / требования к объему гарантии качества | нет | — | — | — | `attributes` |
| Сроки выполнения работ и (или) оказания услуг | нет | — | — | — | `attributes` |
| Требования к гарантии производителя товара | нет | — | — | — | `attributes` |
| Требуется гарантия качества товара, работы, услуги | нет | — | — | — | `attributes` |
| Требуется обеспечение гарантийных обязательств | нет | — | — | — | `attributes` |
| Условия оплаты выполненных работ и (или) оказанных услуг | нет | — | — | — | `attributes` |
| Факс | нет | — | — | — | `attributes` |
| Шаг аукциона | нет | — | — | — | `attributes` |

**Утверждённое решение D130:** новых колонок не добавлять; все 55 подписей оставить в
`prc_procurements.attributes`; отдельно заполнять уже существующую `prc_documents.kind`.

## 7. Ссылки на имена миграций

### Код и действующие документы — кандидаты P8.4

Поиск точных имён всех 27 догоняющих файлов под `app/`, `docs/`, `tests/`, `database/seeders/`
дал **0 ссылок**. Значит после удаления догоняющих файлов P8.2/P8.3 прямых битых ссылок на них в
коде и документации не возникнет.

Ссылки на инфраструктурные файлы существуют и **не являются кандидатами на правку**, потому что
файлы остаются: `MaintainPartitions.php:26` и `PartitionRetentionTest.php:55` → `100050`;
`docs/specs/v2/08-estimate-extraction.md:568,572` → `100050`/`100051`/`100047`;
`docs/03_Dev/database.md:108` → `100068`.

### Планы — только справочный провенанс

Действующий план цитирует догоняющие имена в P2.1/P2.6, P4.2/P4.3, P8.1–P8.3 и
`research/04-p3-runs-design.md`. Архивные планы цитируют как минимум `100048`, `100049`, `100056`,
`100060`, `100061`, `100064`. Архивные carriers и legacy-v1 snapshots append-only и **не правятся**;
эта карта является их соответствием финальным create-миграциям.
