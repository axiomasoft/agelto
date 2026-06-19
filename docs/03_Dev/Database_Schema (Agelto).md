---
type: data-schema
tags: [agelto, database, schema]
created: 2026-06-14
updated: 2026-06-14
---
> ← [[Architecture_Agelto]] | ← [[BRD (Agelto)]] | → [[Procurement_Lifecycle]]
# Схема данных — Agelto

> Канонический источник DDL. Архитектурный контекст и связи — [[Architecture_Agelto]].

---

## Sys — Инфраструктура

### Прокси-менеджмент

```sql
sys_proxies:
  id, host, port, username, password, type, is_active, ...
```

### Логирование

```sql
sys_logs:
  id, level, channel, message, context (json), created_at
```

---

## Platform (plt_*) — Ядро

### Платформы

```sql
plt_platforms:
  id, name, code, url
```

### Кроулеры

```sql
plt_crawlers:
  id, platform_id, url, options (json), filter (json), state (json)
```

### Кроулинг — ссылки, вложения, прогоны, аналитика

> Сверено с миграциями (Вариант A). Сквозной error-блок (`attempts, last_error, last_error_at, next_retry_at, failed_permanently`) — на всех воркер-таблицах.

```sql
plt_crawler_runs:      id, crawler_id, status, cursor_url, current_page,
                       price_from, price_to, price_stack (json), day_from, day_to,
                       cursor_offset, stats (json), last_heartbeat_at, started_at, finished_at,
                       attempts, last_error, last_error_at, next_retry_at, failed_permanently
plt_crawler_links:     id, platform_id, crawler_id, crawler_run_id, detail_url, reg_number,
                       subject, okved, okved_additional, published_at, platform_updated_at,
                       submission_end_at, status, completed_at, [error-блок]
plt_crawler_link_attachments: id, link_id, external_id, name, extension, url, content_hash,
                       status, downloaded_at, [error-блок]
plt_crawler_analytics: id, crawler_id, date, s1_found, s1_errors, s2_docs_indexed, s2_errors,
                       s3_ai_processed, s3_smeta_found, s3_errors, s4_parsed_items, s4_errors
plt_crawler_ignore_links: id, platform_id, external_id, reason_category, reason
```

### DocumentAnalysis — AI-пайплайн

```sql
plt_document_analysis:
  id, model_type, model_id (полиморф.), parent_id
  name, extension, file_fingerprint, source
  gemini_cache_id, gemini_rules_hash, gemini_cache_expires_at
  options (json), status, completed_at
  attempts, last_error, last_error_at, next_retry_at, failed_permanently
  locked_by, locked_until            -- лизинг против дублей Gemini
  timestamps                          -- добавлены (Вариант A: фикс stuck-детектора)

plt_document_analysis_responses:
  id, document_analysis_id, parent_id
  prompt, step, cache_file_id, rules_hash
  success, message, data (json), state (json)

plt_document_analysis_logs:  -- журнал переходов/событий анализа
  id, document_analysis_id, status, message, content (json), trace
```

### PromptRules

```sql
plt_prompt_rules:
  id, key, step, content, version, priority, is_active
  unique(key, version)
```

---

## Eis (ord_*, geo_*, catalog_*, client_*/matches) — Заказы и клиенты

### Заказчики / Заказы / Лоты / Объекты закупки

```sql
ord_customers:   id, full_name, short_name, inn, kpp, ogrn, address, phone, email
ord_orders:      id, reg_number, edition_number, name, status, customer_id, platform_id,
                 initial_price, currency, order_published_at, order_updated_at,
                 order_submission_start_at, order_submission_end_at, order_scoring_at
ord_lots:        id, order_id, lot_number, subject, okved, okpd2, is_centralized,
                 initial_price, dirty_data
ord_lot_infos:   id, lot_id, okved_text, okpd2_text, unit, quantity, unit_price, total_price,
                 additional_info
ord_purchase_objects: id, order_id, okpd2, name, quantity, unit, price, total_cost
```

> [!note] Разрывы (→ [[Procurement_Lifecycle]], [[ADR-002_Winner_Tracking_And_Monitoring|ADR-002]])
> - Связи `ord_orders ↔ plt_crawler_links` нет — Стадия 5 (`OrderParsing`) не подключена,
>   нормализованный результат смет не заполняется.
> - Полей победителя/контракта и `monitoring_status` нет — статус закупки в ЕИС после краулинга
>   не отслеживается (предложено в ADR-002).

### Геолокация

```sql
geo_countries:  id, name, code
geo_locations:  id, country_id, name, type, parent_id
```

### Эталонный каталог (внешний, из [[TcenZor]])

> [!important] Референс-каталог Agelto не строит
> По [[ADR-001_Catalog_From_TcenZor|ADR-001]] эталонный строительный каталог
> приходит из [[TcenZor]] по API ([[Universal_Catalog_Architecture]]). Agelto
> хранит **локальную реплику/кэш** строительного среза для матчинга (базовый
> режим — offline-replica; `POST /catalog/match` к TcenZor — опция). Полная
> модель эталона — [[Universal_Catalog_Model]].

```sql
-- Локальная реплика строительного среза каталога TcenZor (read-mostly):
catalog_products_cache:   id (=tcenzor product_id), category, sku, name, base_unit,
                          primary_standard_code, okpd2_code, ktru_code,
                          attributes jsonb, embedding vector(1536), synced_at
catalog_aliases_cache:    id, product_id, alias_type, value, confidence, is_verified
-- (структура — зеркало эталона TcenZor; синхронизация по updated_at)
```

### Клиентские коллекции + матчинг (домен Agelto)

> [!warning] Статус: SQL-проект, в коде не реализовано
> Ни таблиц, ни моделей, ни pgvector-расширения в миграциях нет. Схема ниже — проект.

```sql
client_collections:       id, client_id, name, created_at
client_collection_items:  id, collection_id, sku, name, unit, price, embedding vector(1536)

-- Матчинг через эталонный слой (ADR-001): позиция сметы → эталон → коллекция
matches:                  id, purchase_object_id,
                          catalog_product_id,        -- → эталон TcenZor (реплика)
                          collection_item_id,        -- → позиция коллекции клиента
                          score float, match_method, is_verified bool
```

> [!note] Изменение схемы matches
> Раньше `matches` связывал `purchase_object_id` ↔ `collection_item_id` напрямую.
> По [[ADR-001_Catalog_From_TcenZor|ADR-001]] добавляется `catalog_product_id` —
> позиция сметы сопоставляется с эталоном, а коллекция клиента подбирается уже
> через эталон. Это даёт трассируемость и переиспользование алиасов.
