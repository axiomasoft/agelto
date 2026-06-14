---
type: architecture
tags: [agelto, architecture, laravel, ai, pgvector]
created: 2026-05-31
updated: 2026-06-13
---

# Архитектура и стек — Agelto

> [!info] Связанные документы
> ← [[BRD]] | ← [[Agelto]] | → [[Dev_Status]] | → [[Risk_Register]] | → [[Procurement_Lifecycle]] | → [[ADR-001_Catalog_From_TcenZor]]
>
> Источник истины по реализации — код `/home/vostrikov/projects/agelto/agelto/` (синхронизировано 2026-06-11)

> [!important] Эталонный каталог — внешний (из [[TcenZor]])
> Решением [[ADR-001_Catalog_From_TcenZor|ADR-001]] Agelto **не строит**
> собственный эталонный MDM. Эталонный строительный каталог (категории/эталоны/
> алиасы/ГОСТ·ОКПД2·КТРУ) приходит из [[TcenZor]] по API
> ([[Universal_Catalog_Architecture]]). Agelto матчит сметы к этому эталону.

---

## 1. Общая схема системы

Реализованный конвейер — 4 стадии (код: `app/Process/*`, описание: `docs/workflow/parsing-process.md` в репозитории):

```
ЕИС (zakupki.gov.ru)
  │  HTTP (headless Chrome + прокси)
  ▼
[Стадия 1: Crawling] — Модуль Eis (EisCrawlingService) поверх Platform/Crawlers
  │  CrawlingItemDto → plt_crawler_links (status: pending)
  ▼
[Стадия 2: DocParsing] — индексация вложений карточки закупки
  │  Фильтр по ключевым словам («смета», «ЛСР», «локальный») → plt_crawler_link_attachments
  ▼
[Стадия 3: PrimaryAnalysis] — AI-скрининг (PrimaryAnalysisJob)
  │  Gemini: «это смета СМР? сумма?» (Excel: первые строки в HTML; PDF: первая страница)
  │  → PrimaryAnalyzed | PrimaryIgnored
  ▼
[Стадия 4: EstimateAnalysis] — глубокий парсинг (EstimateAnalysisJob)
  │  Чанковая обработка по 80 строк → позиции в plt_document_analysis_responses (JSON)
  │  Нормализация в ord_* (Стадия 5 OrderParsing) — НЕ подключена (OrderParsingProcess::run() пуст)
  ▼
[Semantic Matcher] — pgvector (не начат)
  │  Cosine similarity: позиция сметы → ЭТАЛОН (каталог TcenZor) → коллекция поставщика
  │  (эталон — внешний из TcenZor, ADR-001; матчинг идёт через эталонный слой,
  │   а не напрямую смета ↔ коллекция)
  ▼
[Lead Scorer] — ранжирование (не начат)
  ▼
[CRM Integration] — Bitrix24 / amoCRM API (не начата)
```

> [!note] Двухступенчатый матчинг через эталон
> Раньше предполагалось связывать позицию сметы напрямую с коллекцией клиента.
> По [[ADR-001_Catalog_From_TcenZor|ADR-001]] вводится промежуточный эталонный
> слой: **позиция сметы → эталонный товар TcenZor → позиция коллекции клиента**.
> Это даёт переиспользуемые алиасы и нормативку вместо матчинга по случайному
> тексту наименования.

Стадии связываются через `Bus::chain()` (afterCommit); каждый job имеет idempotency guard — проверку статуса перед выполнением.

---

## 2. Технологический стек

| Слой | Технология | Версия |
|:---|:---|:---|
| Backend | PHP / Laravel | 8.5 / 12 |
| Auth | Laravel Fortify (+2FA TOTP) | v1 |
| Модули | nWidart/laravel-modules | — |
| Admin Panel | Filament | v5 |
| Frontend | Inertia.js + Vue 3 | v2 / v3 |
| Роутинг фронтенда | Laravel Wayfinder | v0 |
| Очереди | Laravel Horizon | v5 |
| AI (документы) | Gemini 2.0 Flash (Google API) | — |
| Media Storage | Spatie Media Library | — |
| БД | SQLite (dev) / PostgreSQL (prod) | — |
| Векторный поиск | pgvector (планируется) | — |
| Браузер | BrowserService (headless) | — |
| Прокси | Ротируемый пул (Sys module) | — |
| Контейнеризация | Docker / Docker Compose (prod: Swarm secrets) | — |
| Стиль кода | Pint + PHPStan/Larastan | — |
| Тесты | Pest v4 + architecture tests (`tests/Unit/ArchTest.php`) | — |

---

## 3. Модульная структура (nWidart/laravel-modules)

| Модуль | Назначение | Статус |
|:---|:---|:---|
| **Sys** | Прокси-пул, системные логи | рабочий |
| **Platform** | Кроулеры, Document Analysis, Prompt Rules | рабочий |
| **Eis** | Парсер zakupki.gov.ru | рабочий |

---

## 4. Модуль Sys — Инфраструктура

### 4.1 Прокси-менеджмент

```sql
sys_proxies:
  id, host, port, username, password, type, is_active, ...
```

**ProxyRepository** — предоставляет активный прокси для каждого запроса.

### 4.2 Логирование

```sql
sys_logs:
  id, level, channel, message, context (json), created_at
```

**AppLogger** — обёртка над стандартным Logger с поддержкой `LogOutput::Console` и `LogOutput::DB`.

---

## 5. Модуль Platform — Ядро

### 5.1 Платформы

```sql
plt_platforms:
  id, name, code, url
```

### 5.2 Кроулеры

```sql
plt_crawlers:
  id, platform_id, url, options (json), filter (json), state (json)
```

**AbstractCrawlingService:**
- `startHook(url)` — точка входа, дробление задания
- `crawlingPage(url)` — обход одной страницы
- `crawlingItem(node)` → `CrawlingItemDto`
- `parseAttachments(url)` → `AttachmentDto[]`
- `keepStateOnCrash` — восстановление после сбоя

### 5.3 Кроулинг — ссылки, вложения, прогоны, аналитика

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

### 5.4 Элементы парсинга — УДАЛЕНО

> [!note] Удалено в редизайне БД (Вариант A)
> Таблицы `plt_parsing_items` / `plt_parsing_item_attachments` (модели `Parsing\*`) были мёртвой
> параллельной веткой (см. `AUDIT.md` D2) и **удалены**. Единица работы AI-пайплайна — связка
> `LinkAttachment → DocumentAnalysis` (§5.5). Позиции сметы Стадии 4 пишутся в
> `plt_document_analysis_responses` (JSON); нормализация в `ord_*` — Стадия 5 (`OrderParsing`),
> которая **не подключена**.

### 5.5 DocumentAnalysis — AI-пайплайн

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

### 5.6 PromptRules

```sql
plt_prompt_rules:
  id, key, step, content, version, priority, is_active
  unique(key, version)
```

**QA-регламент изменения промптов** (закрывает риск тихой деградации качества — [[Risk_Register]] R-8):
1. Изменение → новая версия (уже в коде: `unique(key, version)`), старая не редактируется
2. Перед активацией — прогон по golden dataset (эталонные сметы с ожидаемым JSON; собрать ≥ 20 смет из уже распарсенных и проверенных)
3. Метрика прогона не хуже текущей версии → активация; хуже → доработка
4. Деградация в проде → rollback на предыдущую версию (механизм есть), инцидент фиксируется
5. Право изменения — только Admin (NFR [[BRD]] §5)

⚠️ Шаги 2–3 требуют тулинга (artisan-команда прогона по датасету) — не реализовано.

### 5.7 Оркестрация и state machine

Статусы документа — enum `AnalysisStatus` (`app/Enums/AnalysisStatus.php`), 11 значений по фазам:

| Фаза | Статусы |
|:---|:---|
| Подготовка | `Preparing`, `Prepared` |
| AI-скрининг | `PrimaryAnalyzing`, `PrimaryAnalyzed`, `PrimaryIgnored`, `PrimaryAnalysisFailed` |
| Парсинг сметы | `EstimateAnalyzing`, `EstimateAnalyzed`, `EstimateAnalysisFailed` |
| Завершение | `Completed` |
| Ошибки | `Error` |

Принципы (реализованы в коде):
- Переходы только через `DocumentAnalysis::transitionStatus()`; каждый переход пишется в `plt_document_analysis_logs`
- Idempotency: job проверяет текущий статус перед работой — повторная постановка не дублирует обработку
- Цепочка стадий: `Bus::chain()` afterCommit; `failed()` job'а переводит документ в failed-статус + Telegram-уведомление (`PipelineFailedNotification`)
- Счётчик `attempts` и `last_error` — на записи документа

---

## 6. Модуль Eis — Парсер zakupki.gov.ru

### 6.1 Логика EisCrawlingService

```
startHook(url)
  ├─ Если задан диапазон дат → цикл по дням → priceSplitHook(day_url)
  └─ Если нет → priceSplitHook(url)

priceSplitHook(url)
  ├─ total_records из HTML
  ├─ Если 0 → выход
  ├─ Если > 1000 → бинарный поиск по ценам (мин. дистанция: 10 000 ₽)
  └─ Если ≤ 1000 → processAllIntervalPages(url)
```

### 6.2 CrawlingItemDto

```php
CrawlingItemDto {
  externalId: '.registry-entry__header-mid__number',
  url, subject, price (float), publishedAt, updatedAt, submissionEndAt
}
```

---

## 7. Схема данных — Заказы и клиенты

### 7.1 Заказчики / Заказы / Лоты / Объекты закупки

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

### 7.2 Геолокация

```sql
geo_countries:  id, name, code
geo_locations:  id, country_id, name, type, parent_id
```

### 7.3 Эталонный каталог (внешний, из [[TcenZor]])

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

### 7.4 Клиентские коллекции + матчинг (домен Agelto)

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

---

## 8. AI-пайплайн — детали реализации

### 8.1 GeminiService

| Метод | Назначение |
|:---|:---|
| `ensureFileInCloud(analysis)` | Загружает файл в Google Files API |
| `getContextCache(analysis, instruction, ttlHours)` | Создаёт/обновляет контекстный кэш (TTL по умолчанию 6 ч) |
| `generateJson(prompt, data, instruction?, cacheId?)` | Генерирует JSON-ответ |
| `deleteCache(analysis)` | Освобождает кэш после парсинга |

**Инвалидация кэша:** fingerprint файла = `md5(file_name + size + mtime)`; смена fingerprint или hash активных PromptRules → кэш сбрасывается и пересоздаётся.

**Двухрежимный анализ (соответствие стадиям пайплайна §1):**
- **PrimaryAnalysis (стадия 3)** — быстрый запрос без кэша: «есть ли смета?»
- **EstimateAnalysis (стадия 4)** — через контекстный кэш: извлечение всех позиций чанками

### 8.2 SmetaParserService (Excel)

```
1. Загрузить Excel (PhpSpreadsheet)
2. Первые 100 строк → HTML → Gemini → структура шапки
3. Локализовать шапку по якорям (findHeaderCoords)
4. Чанки по 80 строк → Gemini: "Распарси JSON"
5. Собрать Item[] с типами SECTION | ITEM
```

### 8.3 AI-стек эволюция

| Стадия | Технология | Статус |
|:---|:---|:---|
| PDF-анализ | Gemini 2.0 Flash (Google Files API) | ✅ рабочий |
| Excel-парсинг | Gemini 2.0 Flash (direct prompt) | ✅ рабочий |
| Semantic matching | pgvector + BERT/E5 embeddings | 🔧 в разработке |
| OCR для сканов | LayoutLMv3 / TrOCR | ❌ планируется v2 |
| Языковой комплаенс | BERT дообученный (лат ↔ кир) | ❌ планируется v2 |

**Рекомендуемая модель**: `intfloat/multilingual-e5-large` для русскоязычного матчинга.

> [!info] Стратегия независимости от Gemini
> Полная стратегия fallback-провайдеров (RU-облака YandexGPT/GigaChat + локальная LLM),
> cost-метрики и roadmap миграции — [[LLM_Strategy]]. Закрывает риски блокировки Gemini
> (R-6) и 152-ФЗ (R-5).

---

## 9. CRM-интеграция

### 9.1 Целевые CRM

| CRM | Приоритет | Метод |
|:---|:---|:---|
| **Bitrix24** | P1 | REST API / Webhooks |
| **amoCRM** | P1 | REST API |
| **1С:ERP** | P2 | XML обмен |

### 9.2 Структура лида в CRM

```json
{
  "title": "Кабель ВВГнг 3x2.5 — Тендер №2026-123456",
  "contact": {"name": "ООО Стройподряд", "inn": "7701234567"},
  "tender": {"reg_number": "...", "url": "...", "submission_end": "2026-06-15", "initial_price": 15000000},
  "matched_items": [{"smeta_name": "...", "catalog_sku": "...", "match_score": 0.97}]
}
```

---

## 10. Инфраструктура

### 10.1 Очереди (Horizon) — реальные, из кода

| Очередь | Job | Timeout | Retries |
|:---|:---|:---|:---|
| `platform_document_prepare` | `PrepareDocumentJob` | 300 сек | 3 |
| `platform_primary_analysis` | `PrimaryAnalysisJob` | 180 сек | 5 |
| `platform_estimate_analysis` | `EstimateAnalysisJob` | 300 сек | 3 |

Очереди `matching` и `notifications` (CRM) — появятся вместе с соответствующими модулями (не начаты).

### 10.2 Docker Compose

```yaml
# dev (docker-compose.yml)
services:
  app:      # PHP 8.5 / Laravel + nginx
  vite:     # frontend dev server
  docs:     # VitePress
  horizon:  # queue worker
  pgsql:    # PostgreSQL 15
  redis:    # кэш + очереди
```

Prod (`docker-compose.prod.yml`): image-based деплой, Docker Swarm secrets (app_key, db_password, redis_password), health checks, auto-restart.

⚠️ CI/CD-конвейер и staging-окружение не описаны — [[Открытые вопросы]] T9.

---

## 11. Конвенции разработки (из кода)

Зафиксированы в `.ai/guidelines/` репозитория, синхронизация — `composer ai:sync` → CLAUDE.md.

| Конвенция | Где |
|:---|:---|
| **Processor pattern** — бизнес-логика стадий в `app/Process/*/Processor.php`, не в контроллерах | `DocumentProcessor`, `PrimaryAnalysisProcessor`, `EstimateAnalysisProcessor` |
| **DTO** — типизированные структуры (spatie/laravel-data) для всех данных между слоями | `app/Dto/` (`CrawlingItemDto`, `PrimaryAnalysisResponse`, …) |
| **Repositories** — query-логика отдельно от моделей | `app/Repositories/` |
| **Enums** — статусы и стадии только через enum | `AnalysisStatus`, `ProcessStage`, `CrawlingStatus` |
| **Named arguments** — все вызовы методов с именованными аргументами | `.ai/guidelines/php-coding-standards.md` |
| **Транзакции** — multi-step операции в `DB::transaction()` | guideline architecture.md |
| **Тесты** — Pest v4, architecture tests (presets PHP/Security/Laravel), larastan | `tests/` |

Документация в коде: `docs/dev/architecture.md`, `docs/workflow/parsing-process.md` (VitePress) — поддерживать синхронно с этим файлом.

---

## 12. Observability

**Текущее состояние (код):**
- Telegram-уведомления о сбоях пайплайна (`PipelineFailedNotification`, custom `TelegramChannel`)
- `sys_logs` (AppLogger: Console/DB) + лог переходов state machine (`plt_document_analysis_logs`)
- Метрика кроулера `items_count` (аларм-сигнал «ЕИС сменил вёрстку» — BRD §6)

**Целевое (не реализовано, → [[Открытые вопросы]] T5):**
- Метрики стадий пайплайна: время в очереди, длительность, error rate per стадия
- Cost-метрики Gemini: токены/документ, ₽/смета — входной параметр unit-экономики тарифов ([[Risk_Register]] R-7)
- SLO по NFR из [[BRD]] §5 (скрининг ≤ 5 мин, freshness ≤ 4 ч) + алерты на нарушение
- Дашборд (кандидаты: Horizon metrics + Laravel Pulse / Grafana)

---

## 13. DR / Backup

Не реализовано; требования (→ [[Открытые вопросы]] T7):
- Автоматический backup PostgreSQL (расписание, retention, проверка восстановления)
- Хранение оригиналов PDF/XLS: сейчас локальный диск через Spatie Media Library; целевое — S3-совместимое хранилище ([[03_Financial_Model]] §7)
- Целевые RPO/RTO — определить; данные восстановимы повторным кроулингом ЕИС, но история анализов и будущие коллекции клиентов — нет

---

## 14. Масштабирование и rate limiting

- **Квоты Gemini:** лимиты API не специфицированы; нужен бюджет токенов и поведение при превышении (BRD §6: backoff + алерт)
- **Параллелизм очередей:** настройка воркеров Horizon per-очередь; ограничитель параллельных запросов к Gemini
- **Прокси-пул** (Sys) — ограниченный ресурс кроулинга: мониторинг живости и ёмкости
- **Векторный поиск:** pgvector достаточен до ~10M записей; партиционирование/Qdrant — при росте (§15)
- **БД:** шардинг по клиентам — после 100+ клиентов (см. вопрос мультитенантности)

---

## 15. Открытые архитектурные вопросы

| Вопрос | Рекомендация |
|:---|:---|
| ЕИС Web vs FTP XML feeds? | HTTP текущая реализация; FTP-feeds/SOAP API — v2; статус доступа ⚠️ непроверен → [[Procurement_Lifecycle]] §10 |
| Отслеживание результата закупки/победителя? | 🟡 Предложено ([[ADR-002_Winner_Tracking_And_Monitoring\|ADR-002]]), требует бизнес-валидации; HTML-карточка vs SOAP — открытый под-вопрос |
| BERT/E5 модель для embeddings? | `intfloat/multilingual-e5-large` (1024-dim, поддержка рус.) |
| pgvector vs Qdrant для матчинга? | pgvector достаточен до 10M+ записей; Qdrant при >50M |
| Обработка сканированных PDF? | LayoutLMv3 / TrOCR или Qwen2.5-VL / olmOCR 2 — нужна GPU (v2) → [[LLM_Strategy]] разд. 11 |
| Мультитенантность? | Row-level security в PostgreSQL → шардинг при >100 клиентах |
| Тарификация Gemini-запросов для клиентов? | Квота смет/месяц → лимит в PromptRule options |
| Стек observability (§12)? | Кандидат: Horizon + Laravel Pulse на старте, Grafana при росте — [[Открытые вопросы]] T5 |
| Биллинг и учёт квот? | Счётчики + audit trail (BRD F-MON-1/3) — механизм не выбран — [[Открытые вопросы]] T6 |
| RPO/RTO и backup (§13)? | [[Открытые вопросы]] T7 |
| Gemini vs 152-ФЗ / fallback-провайдер? | 🔴 ProviderRouter + RU-fallback + резолюция L3 → [[LLM_Strategy]] разд. 4–5 ([[Risk_Register]] R-5, R-6) |
