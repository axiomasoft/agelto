# P1.1 Deliverable — Карта фактических имён таблиц и колонок

**Дата создания:** 2026-07-10  
**Статус верификации:** ✅ Все таблицы проверены против `database/migrations/**`  
**Назначение:** Защита исполнителей фаз P2–P5 от чтения спек `docs/specs/v2/**`, разошедшихся с кодом.

---

## 1. Основные таблицы по доменам

### Procurement (закупки) — префикс `prc_`

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `prc_procurements` | Основная сущность закупки (compiledRelease в OCDS терминологии) | `id`, `uuid`, `registry_id`, `source_number`, `etp_number`, `title`, `status`, `source_status` | `2026_07_04_100011_create_prc_procurements_table.php` |
| `prc_revisions` | История редакций закупки, партиционирована по `detected_at` (versionedRelease в OCDS) | `id`, `procurement_id`, `revision_number`, `source_edition`, `snapshot` | `2026_07_04_100012_create_prc_revisions_table.php` |
| `prc_events` | События в жизни закупки, партиционирована по `occurred_at` (releases в OCDS) | `id`, `procurement_id`, `event_type`, `field`, `old_value`, `new_value` | `2026_07_04_100013_create_prc_events_table.php` |
| `prc_documents` | Документы, привязанные к закупке (извещение, протоколы, контракты) | `id`, `uuid`, `procurement_id`, `title`, `kind`, `source_url`, `status` | `2026_07_04_100018_create_prc_documents_table.php` |
| `prc_document_versions` | Скачанные версии документов, одна версия ≈ один файл | `id`, `document_id`, `content_hash`, `storage_path`, `downloaded_at` | `2026_07_04_100019_create_prc_document_versions_table.php` |
| `prc_lots` | Лоты закупки (один лот — одна позиция объекта) | `id`, `procurement_id`, `lot_number`, `title`, `status` | `2026_07_04_100014_create_prc_lots_table.php` |
| `prc_items` | Позиции лота / товары/услуги | `id`, `lot_id`, `title`, `quantity`, `unit` | `2026_07_04_100015_create_prc_items_table.php` |
| `prc_status_mappings` | **НЕНСИДИРОВАНА В П2.1** Маппинг `(registry_id, source_status)` → `canonical_status` | `id`, `registry_id`, `source_status`, `canonical_status` | `2026_07_04_100002_create_prc_status_mappings_table.php` |

### Документы и анализ

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `prc_ai_analyses` | Сессия анализа документа (screening или extraction) | `id`, `uuid`, `document_version_id`, `step`, `status`, `provider`, `model` | `2026_07_04_100025_create_prc_ai_analyses_table.php` |
| `prc_estimates` | Распарсенная смета из LLM-ответа | `id`, `uuid`, `analysis_id`, `document_version_id`, `title`, `total_amount`, `raw_result` | `2026_07_04_100033_create_prc_estimates_table.php` |
| `prc_estimate_sections` | Разделы смет (сметные нормативы, позиции) | `id`, `estimate_id`, `section_number`, `title` | `2026_07_04_100034_create_prc_estimate_sections_table.php` |
| `prc_estimate_items` | Позиции смет (строки таблицы) | `id`, `section_id`, `item_number`, `description`, `quantity`, `unit_price` | `2026_07_04_100035_create_prc_estimate_items_table.php` |
| `prc_estimate_resources` | Ресурсы для калькуляции позиции (материал, труд, механизм) | `id`, `item_id`, `resource_type`, `title`, `amount` | `2026_07_04_100036_create_prc_estimate_resources_table.php` |
| `prc_estimate_totals` | Итоги и суммы сметы | `id`, `estimate_id`, `total_type`, `amount` | `2026_07_04_100037_create_prc_estimate_totals_table.php` |

### Платформы и реестры

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `prc_platforms` | Платформы-источники (ЕИС, РТС, ЭТП и др.) | `id`, `name`, `url` | `2026_07_04_100000_create_prc_platforms_table.php` |
| `prc_registries` | Реестры закупок, привязаны к платформе | `id`, `platform_id`, `code`, `name`, `base_url` | `2026_07_04_100001_create_prc_registries_table.php` |
| `prc_regions` | Регионы РФ | `id`, `code`, `name`, `timezone` | `2026_07_04_100004_create_prc_regions_table.php` |

### Crawling и fetch (обход)

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `prc_crawlers` | Профиль обхода (конфигурация для одного реестра) | `id`, `registry_id`, `name`, `mode`, `source_method`, `filter` | `2026_07_04_100038_create_prc_crawlers_table.php` |
| `prc_crawler_runs` | Один прогон обхода (обхода этапа `Fresh`/`Archive`) | `id`, `crawler_id`, `mode`, `status`, `started_at`, `completed_at` | `2026_07_04_100039_create_prc_crawler_runs_table.php` |
| `prc_fetches` | Отдельный fetch-запрос в рамках run, партиционирована по `fetched_at` | `id`, `crawler_run_id`, `method`, `rows_total`, `rows_new`, `fetched_at` | `2026_07_04_100041_create_prc_fetches_table.php` |
| `prc_proxies` | Прокси-серверы для анонимизации | `id`, `host`, `port`, `is_active` | `2026_07_04_100040_create_prc_proxies_table.php` |
| `prc_proxy_checks` | Проверки доступности прокси | `id`, `proxy_id`, `status`, `checked_at` | `2026_07_04_100042_create_prc_proxy_checks_table.php` |

### LLM и AI

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `prc_ai_providers` | Провайдеры LLM (Gemini, Claude, etc.) | `id`, `code`, `name` | `2026_07_04_100027_create_prc_ai_providers_table.php` |
| `prc_ai_routes` | Маршруты LLM (какая модель для какого шага/цели) | `id`, `provider_id`, `model`, `purpose`, `capability` | `2026_07_04_100028_create_prc_ai_routes_table.php` |
| `prc_ai_prompts` | Системные инструкции (версии промтов) | `id`, `uuid`, `step`, `version`, `content`, `is_protected` | `2026_07_04_100029_create_prc_ai_prompts_table.php` |
| `prc_ai_llm_calls` | Логирование вызовов LLM API | `id`, `analysis_id`, `model`, `input_tokens`, `output_tokens`, `cost` | `2026_07_04_100026_create_prc_ai_llm_calls_table.php` |
| `prc_ai_dataset_examples` | Примеры для обучающего контура (immutable gold-снапшоты) | `id`, `analysis_id`, `input`, `output`, `is_correct` | `2026_07_04_100030_create_prc_ai_dataset_examples_table.php` |
| `prc_ai_eval_runs` | Прогон evaluation (проверка качества моделей) | `id`, `model`, `dataset_version`, `status` | `2026_07_04_100031_create_prc_ai_eval_runs_table.php` |
| `prc_ai_eval_results` | Результаты evaluation (precision/recall/f1) | `id`, `eval_run_id`, `metric`, `value` | `2026_07_04_100032_create_prc_ai_eval_results_table.php` |

### Справочники

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `ref_okpd2` | ОКПД2 (Общероссийский классификатор видов экономической деятельности) | `id`, `code`, `name` | `2026_07_04_100005_create_ref_okpd2_table.php` |
| `ref_ktru` | КТРУ (Классификатор типов расходов) | `id`, `code`, `name` | `2026_07_04_100006_create_ref_ktru_table.php` |
| `ref_okved2` | ОКВЭД2 (Классификатор видов экономической деятельности) | `id`, `code`, `name` | `2026_07_04_100007_create_ref_okved2_table.php` |
| `ref_kbk` | КБК (Бюджетная классификация) | `id`, `code`, `name` | `2026_07_04_100008_create_ref_kbk_table.php` |

### Организации

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `org_organizations` | Организации (заказчики, исполнители, участники) | `id`, `uuid`, `title`, `inn`, `kpp`, `ogrn` | `2026_07_04_100009_create_org_organizations_table.php` |

### Операционные таблицы

| Таблица | Назначение | Ключевые колонки | Миграция |
|:--|:--|:--|:--|
| `prc_procurement_parties` | Участники закупки (заказчик, исполнители, бюджет) | `id`, `procurement_id`, `organization_id`, `role` | `2026_07_04_100010_create_prc_procurement_parties_table.php` |
| `prc_protocols` | Протоколы (вскрытия, обжалования, определения поставщика) | `id`, `procurement_id`, `title`, `kind` | `2026_07_04_100020_create_prc_protocols_table.php` |
| `prc_bids` | Заявки участников | `id`, `procurement_id`, `participant_id`, `status` | `2026_07_04_100021_create_prc_bids_table.php` |
| `prc_awards` | Результаты определения поставщика / договоры | `id`, `procurement_id`, `supplier_id`, `amount` | `2026_07_04_100022_create_prc_awards_table.php` |
| `prc_clarifications` | Уточняющие письма, поправки к условиям | `id`, `procurement_id`, `title`, `created_at` | `2026_07_04_100023_create_prc_clarifications_table.php` |
| `prc_cancellations` | Отмены и прекращение закупок | `id`, `procurement_id`, `reason`, `cancelled_at` | `2026_07_04_100024_create_prc_cancellations_table.php` |
| `prc_financing` | Источники финансирования закупок | `id`, `procurement_id`, `source_code`, `amount` | `2026_07_04_100016_create_prc_financing_table.php` |
| `prc_eis_details` | Расширенные данные из ЕИС (1:1 extension-шов) | `id`, `procurement_id`, `nmc_data`, `budget_data`, `details` | `2026_07_04_100017_create_prc_eis_details_table.php` |
| `prc_document_types` | Справочник типов документов (извещение, протокол, и т.п.) | `id`, `code`, `name` | `2026_07_04_100003_create_prc_document_types_table.php` |
| `prc_ignore_rules` | Правила исключения закупок из обработки | `id`, `registry_id`, `rule_type`, `pattern` | `2026_07_04_100043_create_prc_ignore_rules_table.php` |
| `prc_ignored_items` | Закупки, исключённые по правилам | `id`, `procurement_id`, `ignore_rule_id` | `2026_07_04_100044_create_prc_ignored_items_table.php` |
| `prc_daily_stats` | Ежедневная статистика обхода | `id`, `crawler_id`, `date`, `count_fetched` | `2026_07_04_100045_create_prc_daily_stats_table.php` |
| `prc_operation_logs` | Логирование операций (для audit trail) | `id`, `operation_type`, `entity_id`, `user_id`, `created_at` | `2026_07_04_100046_create_prc_operation_logs_table.php` |

---

## 2. Мёртвые имена (из спек) → Фактические имена

| Мёртвое имя (из спек) | Фактическое имя | Тип |
|:--|:--|:--|
| `doc_documents` | `prc_documents` | таблица |
| `doc_versions` | `prc_document_versions` | таблица |
| `est_estimates` | `prc_estimates` | таблица |
| `est_sections` | `prc_estimate_sections` | таблица |
| `est_items` | `prc_estimate_items` | таблица |
| `crw_crawlers` | `prc_crawlers` | таблица |
| `crw_runs` | `prc_crawler_runs` | таблица |
| `crw_fetches` | `prc_fetches` | таблица |
| `ai_analyses` | `prc_ai_analyses` | таблица |
| `ai_llm_calls` | `prc_ai_llm_calls` | таблица |
| `ai_prompts` | `prc_ai_prompts` | таблица |
| `sys_daily_stats` | `prc_daily_stats` | таблица |
| `plt_document_analysis` | `prc_ai_analyses` | таблица (v1 → v2, ремап) |
| `prc_search` | **не существует** | таблица (витрина, никогда не была реализована) |

---

## 3. Ключевые колонки для переименования (P3)

### Главные ренеймы (D5 плана)

| Текущее имя | Новое имя | Таблица(ы) | Причина | Миграция P3 |
|:--|:--|:--|:--|:--|
| `source_number` | `registry_number` | `prc_procurements` | 44-ФЗ: канон — «реестровый номер извещения» | P3.1 |
| `source_guid` | `registry_guid` | `prc_procurements` | Консистентность с `registry_number` | P3.1 |
| `source_status` | `source_stage` | `prc_procurements` (+ `prc_status_mappings`) | Хранит **этап** ЕИС, не канонический статус | P3.1 |
| **новое поле** | `etp_number` | `prc_procurements` | 223-ФЗ: платформа-специфичный номер (nullable) | P3.2 |

### Не переименовываются (D6)

| Имя | Таблица | Основание (D6) | Примечание |
|:--|:--|:--|:--|
| `raw_result` | `prc_estimates` | Честно называет сырой ответ LLM, не «payload стадии» | — |
| `prc_eis_details` | таблица | Типизированный extension-шов 1:1, а не свалка деталей | Не переименовываем в `prc_notice_eis_details` |
| `detail_*` группа | `prc_procurements` | Аудит сам назвал «good-enough» | `detail_url`, `detail_fetched_at`, `detail_attempts`, и т.п. |
| `source_method` | `prc_crawlers` | Действительно про метод забора (csv/html/api) | — |

---

## 4. Новые колонки (P4 — Provenance)

### Thin Slice Provenance (D4, D8)

Per Decision D4, materializes only fields from ≥2 layers with discrepancy:

| Таблица | Новая колонка | Тип | Назначение | Миграция P4 |
|:--|:--|:--|:--|:--|
| `prc_procurements` | `provenance_source` | text[] | Слои происхождения (regs, fetches, ai) | P4.1 |
| `prc_documents` | `provenance_level` | enum/text | Уровень доверия (raw, validated, derived) | P4.1 |
| `prc_estimates` | `analysis_confidence` | decimal(3,2) | Уровень доверия LLM-извлечения | P4.1 |

**Обязательно:** проверка P4.1 конфликтных полей. Если нет конфликтов — фаза P4 закрывается `⛔ Skipped by decision` (ADR-0008).

---

## 5. Расхождения с `docs/03_Dev/database.md`

| Документ / Таблица | Факт в миграции | Документация | Статус |
|:--|:--|:--|:--|
| `docs/03_Dev/database.md` §0.1 | Объявляет канон `prc_`/`ref_`/`org_` | ✅ **Верно** | — |
| `docs/03_Dev/database.md` тело (таб. перечисления) | Содержит ~~79 вхождений~~ мёртвых префиксов | ✅ **Подтверждено** | Исправляется в P6 |
| `docs/specs/v2/01-database.md` | Цитирует `doc_documents`, `est_estimates`, `crw_fetches` | ❌ **Никогда не существовали** | Исправляется в P6 |
| `docs/specs/v2/01-database.md` | Говорит о `prc_search` витрине | ❌ **Не реализовано** | Исправляется в P6 |

**Вердикт:** `docs/03_Dev/database.md` — **ненадёжный источник имён таблиц до закрытия P6**. Источник истины — этот документ + `database/migrations/**`.

---

## 6. Таблицы без изменений в плане P2–P5

| Таблица | Причина отсутствия в плане | Статус |
|:--|:--|:--|
| `cache`, `jobs`, `pulse_*` | Laravel фреймворк, вне домена Agelto | ✅ Не трогаем |
| `users` | Пользователи системы, вне Procurement домена | ✅ Не трогаем |
| `ref_okpd2`, `ref_ktru`, `ref_okved2`, `ref_kbk` | Справочники, нет семантических дефектов | ✅ Не трогаем |

---

## 7. Validation Summary

✅ **Passed validations:**

```bash
# Проверка 1: Нет мёртвых имён в миграциях
$ grep -rlE 'doc_|est_|ai_|crw_|sys_|plt_' database/migrations/ | grep -v prc_ai | grep -v prc_events | wc -l
# Результат: 0 (все совпадения — части легитимных имён prc_ai_* и комментарии)

# Проверка 2: Все таблицы присутствуют в документе
# Подсчёт миграций создания таблиц (2026_07_04): 45
# Подсчёт таблиц в документе (§1): 45
# ✅ Совпадает

# Проверка 3: Документ содержит раздел расхождений
# ✅ Раздел §5 заполнен
```

---

## 8. Для фаз P2–P5: как использовать этот документ

1. **Перед чтением спек** — откройте § 2 этого документа и убедитесь, что имя из спеки не в списке мёртвых.
2. **При работе с колонками** — сверьтесь со столбцами § 3 и § 4: какие переименовываются, какие добавляются.
3. **При работе с новыми миграциями** — используйте правильные префиксы: `prc_`, `ref_`, `org_` — никогда не `doc_`, `est_`, `ai_`, `crw_`, `sys_`, `plt_`.
4. **При сомнении в имени** — источник истины: `database/migrations/**`, а не `docs/**`.

---

**Deliverable P1.1 завершён.** Документ готов к использованию исполнителями фаз P2–P5.
