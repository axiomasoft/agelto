---
type: architecture
tags: [agelto, architecture, laravel, ai, pgvector]
created: 2026-05-31
updated: 2026-06-13
---

# Архитектура и стек — Agelto

> [!info] Связанные документы
> ← [[BRD (Agelto)]] | ← [[Agelto]] | → [[Dev_Status]] | → [[Risk_Register (Agelto)]] | → [[Procurement_Lifecycle]] | → [[ADR-001_Catalog_From_TcenZor]]
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

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

**ProxyRepository** — предоставляет активный прокси для каждого запроса.

### 4.2 Логирование

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

**AppLogger** — обёртка над стандартным Logger с поддержкой `LogOutput::Console` и `LogOutput::DB`.

---

## 5. Модуль Platform — Ядро

### 5.1 Платформы

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

### 5.2 Кроулеры

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

**AbstractCrawlingService:**
- `startHook(url)` — точка входа, дробление задания
- `crawlingPage(url)` — обход одной страницы
- `crawlingItem(node)` → `CrawlingItemDto`
- `parseAttachments(url)` → `AttachmentDto[]`
- `keepStateOnCrash` — восстановление после сбоя

### 5.3 Кроулинг — ссылки, вложения, прогоны, аналитика

> Сверено с миграциями (Вариант A). Сквозной error-блок (`attempts, last_error, last_error_at, next_retry_at, failed_permanently`) — на всех воркер-таблицах.

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

### 5.4 Элементы парсинга — УДАЛЕНО

> [!note] Удалено в редизайне БД (Вариант A)
> Таблицы `plt_parsing_items` / `plt_parsing_item_attachments` (модели `Parsing\*`) были мёртвой
> параллельной веткой (см. `AUDIT.md` D2) и **удалены**. Единица работы AI-пайплайна — связка
> `LinkAttachment → DocumentAnalysis` (§5.5). Позиции сметы Стадии 4 пишутся в
> `plt_document_analysis_responses` (JSON); нормализация в `ord_*` — Стадия 5 (`OrderParsing`),
> которая **не подключена**.

### 5.5 DocumentAnalysis — AI-пайплайн

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

### 5.6 PromptRules

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

**QA-регламент изменения промптов** (закрывает риск тихой деградации качества — [[Risk_Register (Agelto)]] R-8):
1. Изменение → новая версия (уже в коде: `unique(key, version)`), старая не редактируется
2. Перед активацией — прогон по golden dataset (эталонные сметы с ожидаемым JSON; собрать ≥ 20 смет из уже распарсенных и проверенных)
3. Метрика прогона не хуже текущей версии → активация; хуже → доработка
4. Деградация в проде → rollback на предыдущую версию (механизм есть), инцидент фиксируется
5. Право изменения — только Admin (NFR [[BRD (Agelto)]] §5)

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

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

> [!note] Разрывы (→ [[Procurement_Lifecycle]], [[ADR-002_Winner_Tracking_And_Monitoring|ADR-002]])
> - Связи `ord_orders ↔ plt_crawler_links` нет — Стадия 5 (`OrderParsing`) не подключена,
>   нормализованный результат смет не заполняется.
> - Полей победителя/контракта и `monitoring_status` нет — статус закупки в ЕИС после краулинга
>   не отслеживается (предложено в ADR-002).

### 7.2 Геолокация

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

### 7.3 Эталонный каталог (внешний, из [[TcenZor]])

> [!important] Референс-каталог Agelto не строит
> По [[ADR-001_Catalog_From_TcenZor|ADR-001]] эталонный строительный каталог
> приходит из [[TcenZor]] по API ([[Universal_Catalog_Architecture]]). Agelto
> хранит **локальную реплику/кэш** строительного среза для матчинга (базовый
> режим — offline-replica; `POST /catalog/match` к TcenZor — опция). Полная
> модель эталона — [[Universal_Catalog_Model]].

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

### 7.4 Клиентские коллекции + матчинг (домен Agelto)

> [!warning] Статус: SQL-проект, в коде не реализовано
> Ни таблиц, ни моделей, ни pgvector-расширения в миграциях нет. Схема ниже — проект.

> 📦 Полная схема перенесена в [[Database_Schema (Agelto)]]

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

> 📦 Полный контракт перенесён в [[API_Endpoints (Agelto)]]

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

⚠️ CI/CD-конвейер и staging-окружение не описаны — [[Открытые вопросы (Agelto)]] T9.

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

**Целевое (не реализовано, → [[Открытые вопросы (Agelto)]] T5):**
- Метрики стадий пайплайна: время в очереди, длительность, error rate per стадия
- Cost-метрики Gemini: токены/документ, ₽/смета — входной параметр unit-экономики тарифов ([[Risk_Register (Agelto)]] R-7)
- SLO по NFR из [[BRD (Agelto)]] §5 (скрининг ≤ 5 мин, freshness ≤ 4 ч) + алерты на нарушение
- Дашборд (кандидаты: Horizon metrics + Laravel Pulse / Grafana)

---

## 13. DR / Backup

Не реализовано; требования (→ [[Открытые вопросы (Agelto)]] T7):
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
| Стек observability (§12)? | Кандидат: Horizon + Laravel Pulse на старте, Grafana при росте — [[Открытые вопросы (Agelto)]] T5 |
| Биллинг и учёт квот? | Счётчики + audit trail (BRD F-MON-1/3) — механизм не выбран — [[Открытые вопросы (Agelto)]] T6 |
| RPO/RTO и backup (§13)? | [[Открытые вопросы (Agelto)]] T7 |
| Gemini vs 152-ФЗ / fallback-провайдер? | 🔴 ProviderRouter + RU-fallback + резолюция L3 → [[LLM_Strategy]] разд. 4–5 ([[Risk_Register (Agelto)]] R-5, R-6) |
