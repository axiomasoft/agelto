# ТЗ v2 · 04 — LLM-провайдеры и извлечение

> Схема — `01-database.md` (§6 AI-анализ и LLM-конфиг). Пайплайн — `03-document-pipeline.md`. Обоснование выбора пакета — `research/tz-v2/llm-abstraction.md`.

## 1. Принципы

- **Домен зависит от собственного контракта `LlmProvider`, не от внешнего пакета напрямую.** `laravel/ai` и Prism — оба на линии 0.x; для greenfield с длинным горизонтом прятать их за тонким фасадом, конкретный пакет — сменяемый адаптер.
- **Матрица роутинга**: «тип документа × шаг → провайдер+модель», настраивается в админке (`ai_routes`), без деплоя. Дешёвый скрининг и дорогой разбор могут идти на разных провайдерах.
- **Экономия токенов — обязательна**: context caching (Gemini `cachedContents`), скрининг минимальным ответом, «схема как код» и промпты из БД в кэшируемой системной части.
- **Полная воспроизводимость**: каждый вызов (`ai_llm_calls`) фиксирует провайдера, модель, версии промптов, usage-токены, стоимость, ссылки на сырые payload'ы в object storage.

## 2. Контракт провайдера

```php
interface LlmProvider
{
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse;

    public function completeStructured(
        LlmPrompt $prompt,
        JsonSchema $schema,
        LlmOptions $options,
    ): LlmStructuredResponse;
}

// Не у всех провайдеров — отдельный опциональный контракт.
interface SupportsContextCaching
{
    public function createCache(CacheableContent $content, LlmCacheOptions $options): LlmCacheHandle;
    public function refreshCache(LlmCacheHandle $handle, CarbonInterval $ttl): void;
    public function deleteCache(LlmCacheHandle $handle): void;
}
```

- `LlmOptions`: `provider`, `model`, `temperature`, `maxTokens`, опционально `cacheHandle`.
- `LlmStructuredResponse`: `$data` (типизированный DTO/массив по схеме) + `$usage` (`promptTokens`, `completionTokens`, `cachedTokens`) + `$raw` (ссылка на сырой ответ).
- Адаптеры (`PrismGeminiProvider`, `PrismOpenAiCompatibleProvider` для локальных vLLM/Ollama, при необходимости `LaravelAiProvider`) реализуют контракт и живут в `app/Services/Llm/`.

### 2.1 Базовая реализация

- **Prism** — базовый транспорт: context caching Gemini документирован официально (`$provider->cache(...)` + `cachedContentName`), богатый structured-output (`ObjectSchema`/`AnyOfSchema`, `nullable`), кастомный `base_url` для локальных OpenAI-совместимых серверов (vLLM/Ollama). `laravel/ai` — допустимая альтернатива за тем же фасадом (официальный, agent-паттерн); выбор не влияет на домен.
- **Дописываем поверх Prism** (не покрыто из коробки): единый CRUD-сервис кэшей (list/refresh TTL/delete), row-range оркестратор больших таблиц (§4), golden-раннер и метрики (§5), shadow-режим (§5).
- **Два уровня кэша Gemini.** *Implicit caching* (вкл. по умолчанию для 2.5+, порог ~2048–4096 токенов, ~90% экономии на повторном входном префиксе) срабатывает само, когда системная часть стабильна между row-range вызовами — часть экономии бесплатна, без управления жизненным циклом. *Explicit caching* (`cachedContents`) нужен там, где требуется **гарантированный** cache-hit и контроль TTL (длинный документ, много диапазонов) — ради этого и держим CRUD-сервис. Порядок: полагаемся на implicit, explicit включаем для крупных смет, где implicit ненадёжен.
- Ограничение Gemini (транслируется Prism): `cachedContent` несовместим с `system_instruction`/`tools` в одном запросе — системную часть и инструменты фиксируем **на этапе создания кэша**; structured output с кэшем — через `response_schema`-режим, не через tool-calling.

> Актуальные диаграммы по фактическому коду — `docs/03_Dev/llm-providers.md` (Д6: `AiRouteResolver` → адаптеры → capability-кэширование), `docs/03_Dev/ai/prompts.md` (Д3: композиция промта с границей кэшируемого префикса).

## 3. Матрица роутинга и промпты

### 3.1 Роутинг (`ai_routes`)

- Строка матрицы: `(document_type, purpose) → provider + model + options`, `priority`, `is_active`, `shadow`.
- `purpose`: `screening`, `extract_passport`, `extract_rows`, `extract_totals`, `eval`, `shadow` (полный набор — `01` §6c, совпадает с `ai_llm_calls.purpose`).
- Пример стартовой матрицы:
  - `(any, screening)` → дешёвая быстрая модель (кандидат на локальную LLM в первую очередь).
  - `(estimate, extract_*)` → Gemini (context caching + vision для сканов).
- Резолвер выбирает активную строку с наивысшим приоритетом; при недоступности провайдера — следующий приоритет (fallback).

### 3.2 Промпты (`ai_prompts`)

- Промпты — в БД (канон v1 `prompt_rules`): `key`, `step`, `content`, `version`, `priority`, `is_active`; отбор по шагу с сортировкой (Global → GlobalStep → конкретный шаг) и приоритету.
- **«Схема как код»**: PHP-DTO целевой схемы (паспорт сметы, позиция, ресурс) очищается и дописывается в системную инструкцию — схема служит частью промпта. Меняем DTO → меняется контракт извлечения.
- Системная часть (промпты + схема) — стабильна между вызовами одного документа → кладётся в context cache, оплачивается один раз.
- `ai_llm_calls.prompt_version_set` фиксирует ключи+версии участвовавших промптов — воспроизводимость и связь с датасетом.

## 4. Извлечение больших таблиц (оркестратор)

Регламент разбора смет на сотни строк — многошаговый (детали пайплайна — `03` §3.5; контракты ответов, transport-limits/preflight, оконный fallback, checkpoint-requeue — `08-estimate-extraction.md`), здесь — LLM-механика:

1. Системная часть (промпты + схема + документ) → создать context cache, TTL под длительность разбора.
2. Позиции — построчными диапазонами (`extract_rows`), каждый вызов с `cachedContentName`, строгой JSON-схемой, `temperature=0`, явными `row_index`.
3. Merge + детерминированная валидация в коде: сверка ожидаемого/фактического числа строк, типов, дублей, сходимости сумм.
4. Аномальный диапазон → точечный re-run, не весь документ.
5. По завершении — удалить кэш (CRUD-сервис), зафиксировать суммарный usage/стоимость.

## 5. Eval и shadow-режим

- **Golden dataset** (`ai_dataset_examples`): 100–300 репрезентативных документов с эталонным разбором (`gold`), версионируется вместе со схемой; источники разбора — LLM / ручная разметка / исправленный оператором. Служит и для eval, и как обучающий датасет локальной модели.
- **Метрики** (`ai_eval_runs` / `ai_eval_results`): field-level precision/recall/F1 на паре (row, field), row coverage, row exact-match; для длинных текстов — нестрогое сравнение (ANLS). Раннер — Artisan-команда, прогоняет текущий пайплайн по голдену, считает метрики, отчёт + CI-гейт (fail при регрессии сверх допуска). Готового PHP-инструмента под это нет — раннер свой.
- **Shadow-режим**: `ai_routes.shadow=true` — при основном вызове параллельно (queued job, Horizon) прогоняется теневой провайдер (кандидат — локальная LLM); результат и метрики пишутся в отдельные записи (`purpose=shadow`, тот же документ) для сравнения качества/стоимости **до** переключения. Опционально — экспорт трейсов во внешнюю observability-платформу по HTTP, вне критического пути.

## 6. Учёт стоимости

- Каждый вызов → `ai_llm_calls`: `prompt_tokens`, `completion_tokens`, `cached_tokens`, `total_tokens`, `cost_micros`, `duration_ms`, ссылки на request/response в object storage.
- Партиционирование `ai_llm_calls` по месяцу (01-database §6) — аналитика расхода по провайдеру/модели/цели, дэшборд в админке (`05-admin-observability.md`).
- Троттлинг вызовов (Redis) — защита от превышения лимитов провайдера; конфигурируемо, отключаемо в тестах.

## 7. Локальная LLM (задел)

- Подключается тем же контрактом `LlmProvider` через OpenAI-совместимый адаптер (vLLM/Ollama, кастомный `base_url`) — код домена не меняется.
- Путь ввода: сначала на дешёвых шагах (скрининг) и в shadow-режиме против Gemini; переключение через матрицу роутинга в админке по достижении приемлемых eval-метрик.
- Выбор конкретной модели, GPU-требования и обучение (fine-tune) — **вне текущего ТЗ**; ТЗ фиксирует контракт, сбор датасета и eval-контур, делающие переключение возможным без переделки.

## 8. Открытые вопросы / риски

- **Лимит explicit-кэша Gemini — 10 МБ** на кэшируемый blob/текст (официальный лимит). Крупные документы (конвертированные Excel→PDF сметы на сотни строк, сканы для vision-ветки, `03` §3.3) могут превышать порог → создание `cachedContent` невозможно, деградация тихая. **Fallback**: при превышении порога — идти без explicit-кэша (полагаясь на implicit caching), для сканов — даунскейл/сжатие до порога; оркестратор (§4) обязан ловить ошибку создания кэша и переключаться на путь без него, а не падать. Порог проверять до создания кэша.
- Точная поддержка `cachedContent` в `laravel/ai` — по вторичному источнику; при выборе этого пакета перепроверить по официальному changelog (Prism — подтверждено официально).
- CRUD жизненного цикла кэшей и кэш в streaming — дописываем сами поверх Prism.
- Нагрузочное поведение (retry/backoff/rate-limit провайдера на объёме) — PoC до фиксации.
- Размер row-range и пороги валидации — калибровка на реальных сметах (см. `03` §7).
