# LLM-абстракция для Laravel и пайплайн извлечения данных — research-выжимка

## 1. `laravel/ai` (github.com/laravel/ai) — статус на середину 2026

- Официальный пакет, позиционируется как "the official AI SDK for Laravel", интегрирован в laravel.com/ai и в основную документацию (laravel.com/docs/13.x/ai-sdk). [laravel.com/ai](https://laravel.com/ai), [packagist.org/packages/laravel/ai](https://packagist.org/packages/laravel/ai)
- Версионность: на момент запроса — линия **0.x** (называются версии от v0.2.x до v0.8.x в разных источниках), т.е. **пакет ещё не в GA (1.0)**. Статья от марта 2026 говорит про "SDK goes stable" в связке с Laravel 13, но это не равно семантическому 1.0. [dev.to](https://dev.to/hafiz619/laravel-ai-sdk-goes-stable-on-march-17-what-changed-and-what-to-check-before-you-ship-57ll)
- Архитектурная философия — **Agent как первоклассный объект** (PHP-атрибуты `#[Provider]`, `#[Model]`), а не просто fluent-вызовы. Это ближе к Laravel-конвенциям (тестируемость через `Agent::fake()`), но требует больше boilerplate на каждый use-case.
- Провайдеры: OpenAI, Anthropic, **Gemini**, Groq, Mistral, **Ollama** — все из коробки, единый `config/ai.php`.
- Локальные/OpenAI-совместимые эндпоинты: Ollama — first-class провайдер с `url` в конфиге (`http://localhost:11434/api`). Для vLLM и любых OpenAI-совместимых серверов используется `driver: 'openai'` с кастомным `url` (подтверждено также для LiteLLM/Azure-шлюзов). [github.com/laravel/ai](https://github.com/laravel/ai)
- Файлы/документы: два паттерна — inline-attachments (`Files\Document::fromStorage()/fromPath()`) на каждый запрос, и pre-stored (`Document::fromUpload(...)->put(provider: Lab::Gemini)` с последующим обращением по id, что экономит повторную загрузку). Официальная матрица поддержки file-провайдеров: **OpenAI, Anthropic, Gemini**. Ollama файлы **не поддерживает**.
- Structured output: заявлен как core-фича ("agents with tools, memory, structured outputs"), реализация — JSON-schema-подобная абстракция поверх нативных механизмов провайдера (Gemini response_schema и т.п.).
- Streaming: поддержан по всем провайдерам, включая Gemini, через `->stream()`, возвращающий итератор дельт; в свежих версиях добавлен **mid-stream failover** между провайдерами (не бросает исключение при обрыве потока у primary — переключается на следующий в цепочке). [laravelmagazine.com/laravel-ai-v0-6-8](https://laravelmagazine.com/laravel-ai-v0-6-8)
- **Context caching Gemini**: по неподтверждённым, но правдоподобным данным, **с версии v0.6.8 добавлена явная поддержка `cachedContent`** через `providerOptions(['cachedContent' => 'projects/.../cachedContents/abc123'])`. Это заметно снимает один из главных рисков пакета для вашего кейса. **Важно: это не удалось подтвердить по официальному changelog/GitHub напрямую** — источник вторичный (laravelmagazine.com), стоит перепроверить на актуальной версии перед закладкой в архитектуру.
- Usage/токены: `$response->usage()['promptTokens']`, `candidatesTokens`, для Gemini 2.5+ — `cacheHitTokens`. Для streaming — usage доступен по завершении потока (аккумуляция чанков или provider-specific аксессор).

## 2. Prism (prism-php/prism) — то же самое

- Активно развивающийся community-пакет, **не официальный Laravel**, но фактически стал стандартом де-факто для мульти-провайдерных интеграций в экосистеме. Сайт документации: [prismphp.com](https://prismphp.com)
- Версия на конец 2025 — **v0.91.0** (октябрь 2025), >100 релизов, высокий темп итераций. Тоже линия 0.x — breaking changes между минорными версиями возможны. [github.com/prism-php/prism/releases](https://github.com/prism-php/prism/releases)
- Провайдеры "из коробки": Anthropic, OpenAI, Ollama + пакетно/через адаптеры — **Gemini** (нативный, `Provider::Gemini`), Bedrock, Groq, xAI, DeepSeek, OpenRouter, VoyageAI, ElevenLabs, Cloudflare Workers AI.
- Кастомный `base_url` для локальных моделей: у **OpenAI-провайдера** есть `url` в `config/prism.php` (по умолчанию `https://api.openai.com/v1`) — это прямой путь для **vLLM** (OpenAI-совместимый API). Плюс есть **отдельный первоклассный Ollama-провайдер** (`OLLAMA_URL`, дефолт `http://localhost:11434/v1`) с провайдер-специфичными опциями (`num_ctx`, `top_p`). Есть **runtime-override** конфига провайдера прямо в вызове (`->using(Provider::OpenAI, $model, ['url' => ..., 'api_key' => ...])`) — удобно для мульти-тенантных/мульти-локейшн сценариев. [prismphp.com/providers/openai.html](https://prismphp.com/providers/openai.html), [prismphp.com/getting-started/configuration/](https://prismphp.com/getting-started/configuration/)
- Structured output: зрелый и провайдер-агностичный API схем — `ObjectSchema`, `StringSchema`, `NumberSchema` (с `minimum/maximum/multipleOf`), `ArraySchema`, **`AnyOfSchema`** (полиморфные поля), поддержка `nullable`. Именно у **Gemini** в Prism структурные схемы богаче, чем у части других провайдеров (это явно отмечено в доках). [prismphp.com/core-concepts/structured-output.html](https://prismphp.com/core-concepts/structured-output.html)
- Документы/vision: есть generic `Document`-value-object (`Document::fromLocalPath()`), провайдер-независимый API `Prism::text()->withPrompt('...', [Document::fromLocalPath(...)])`. Для Gemini 3 — `mediaResolution` (`MEDIA_RESOLUTION_HIGH`) для повышения точности распознавания сканов. [prismphp.com/input-modalities/documents.html](https://prismphp.com/input-modalities/documents.html)
- **Context caching Gemini — реализован и документирован официально**, это ключевое отличие от `laravel/ai` по степени подтверждённости:
  1. Явное создание кэша через `$provider->cache(model, messages, systemPrompts, ttl)` (провайдер получаете через `Prism::provider(Provider::Gemini)`).
  2. Использование в дальнейших запросах через `->withProviderOptions(['cachedContentName' => $object->name])`.
  3. Задокументированы **ограничения Gemini API**, которые Prism прозрачно транслирует: нельзя одновременно использовать `cachedContent` и `system_instruction`/`tools` в одном запросе — все системные промпты и тулы должны быть зафиксированы **на этапе создания кэша**. Это значит: если кэш создан без tools, structured output через tool-calling с этим кэшом не сработает — нужно использовать `response_mime_type`/`response_schema`-режим. [prismphp.com/providers/gemini/](https://prismphp.com/providers/gemini/)
  4. Не покрыто из коробки: управление жизненным циклом кэшей единым API (list/update TTL/delete) и явная поддержка кэша в streaming — придётся дописывать поверх HTTP-клиента Prism.
- Streaming: типизированные `StreamEvent`-объекты (`TextDeltaEvent`, `StreamEndEvent`, `ToolCallEvent` и т.д.), usage доступен только на `StreamEndEvent`. Есть готовые обёртки `asEventStreamResponse()` (SSE) и `asDataStreamResponse()` (Vercel AI SDK data-protocol) с callback-хуком для персиста usage. Провайдер-независимая нормализация событий (Anthropic/OpenAI/Gemini/Workers AI — единая модель событий). [github.com/prism-php/prism/issues/381](https://github.com/prism-php/prism/issues/381)
- Есть также **Prism Server** — экспонирование собственных промптов/моделей как OpenAI-совместимого эндпоинта наружу (полезно, если захотите подключать внешние инструменты типа ChatGPT-клиентов к внутреннему пайплайну).

## 3. Сравнение: laravel/ai vs Prism vs google-gemini-php/client

| Критерий | laravel/ai | Prism-PHP | google-gemini-php/client (прямой) |
|---|---|---|---|
| Официальность | Первая сторона (Laravel) | Community, де-факто стандарт | Community, Gemini-only |
| Версия / зрелость | 0.x, "goes stable" март 2026 (неточно подтверждено) | 0.91.x, >100 релизов, высокий темп | Значительно менее активен, уже проверить актуальность |
| Мульти-провайдерность | Да (OpenAI/Anthropic/Gemini/Groq/Mistral/Ollama) | Да, шире по адаптерам (+ Bedrock/xAI/DeepSeek/OpenRouter/Workers AI) | Нет, только Gemini |
| Локальные модели (vLLM/Ollama) | Да, через `driver: openai` + custom `url`, Ollama first-class | Да, аналогично + runtime override конфига | Не применимо |
| Structured output | Заявлен, JSON-schema-подобный, отмечены проблемы с nested `additionalProperties: false` в 2025 | Зрелый, богатый набор Schema-классов, `AnyOfSchema` для Gemini | Прямой доступ к нативному Gemini `response_schema`, без абстракции — макс. контроль |
| Gemini File API | Да (pre-stored attachments, provider-scoped id) | Да (`Document::fromLocalPath`), генерика по всем провайдерам | Да, нативно, полный контроль над upload/lifecycle |
| Gemini `cachedContents` (context caching) | Заявлено с v0.6.8 (providerOptions `cachedContent`) — **не подтверждено официальным источником** | **Официально задокументирован** метод `$provider->cache(...)` + `cachedContentName` в providerOptions, включая описание ограничений API | Нативный REST-доступ, но **нет готовых helper'ов** под `cachedContents` — пишете сами поверх транспорта |
| Streaming | Да, + mid-stream failover между провайдерами | Да, типизированный event-stream, SSE/data-protocol helpers | Зависит от клиента, обычно голый REST/SSE |
| Usage/токены | Есть, включая `cacheHitTokens` для Gemini 2.5+ | Есть, только на `StreamEndEvent`, включая `thought_tokens` для reasoning-моделей | Нативно из ответа Gemini API, без нормализации |
| Философия | Agent-as-object, атрибуты, тесно про Laravel-конвенции | Fluent driver API, provider-agnostic, ближе к "adapter-слою" | Полный контроль, нулевая абстракция |

Ключевой факт от Google (подтверждён официально): Gemini API имеет **implicit caching** (включено по умолчанию для Gemini 2.5+, порог ~2048 токенов для Flash/Pro, ~4096 для новых 3.x-моделей, экономия ~90% на закэшированных input-токенах) и **explicit caching** через ресурс `cachedContents` (create/get/list/update/delete, `ttl`/`expireTime`, лимит 10 МБ на кэшируемый blob/текст, минимальный TTL — 1 минута, максимального TTL нет). File API и cachedContents работают вместе: файл сначала грузится через File API, затем передаётся в `contents` при создании кэша, дальше в generateContent передаётся только `cachedContent: cache.name`. [cloud.google.com/vertex-ai/.../context-cache](https://cloud.google.com/vertex-ai/generative-ai/docs/context-cache/context-cache-for-tuned-gemini) — это платформенный факт, не зависящий от выбора PHP-обёртки, и именно его должна прозрачно транслировать выбранная абстракция.

## 4. Паттерны multi-step извлечения больших таблиц из PDF через LLM

Собранные данные сходятся на одном выводе: **"дай мне всю таблицу в 300+ строк одним JSON" — структурно хрупкий паттерн**, ломается по трём причинам:
1. **Output token cap** — модель физически не может выдать сколь угодно длинный JSON, хвост тихо обрезается.
2. **"Lost in the middle"** — эмпирически модели хуже извлекают значения из середины длинного контента/таблицы, чем из начала/конца.
3. **Schema drift на длинных выходах** — модель начинает пропускать обязательные поля, менять имена ключей, использовать "same as above" вместо копирования значений, чтобы "сэкономить токены". [landing.ai/blog/why-long-documents-break-extraction-system](https://landing.ai/blog/why-long-documents-break-extraction-system), [stackoverflow.com/.../token-limit-large-json](https://stackoverflow.com/questions/79699282/how-to-handle-token-limit-when-processing-large-json-response-with-mcp-client-se)

Рабочая архитектура — **pipeline, а не один вызов**:
- **Layout-aware сегментация, не постраничная**: таблица — это семантическая единица, страницы — визуальный артефакт. Таблицы, распластанные на несколько страниц, нужно детектировать по совпадению колонок-заголовков и склеивать в единый логический объект до чанкования.
- **Row-range chunking с явными индексами**: не "извлеки все 300 строк", а "извлеки строки 1–40 из таблицы X, поля: row_index, name, price...". Размер диапазона подбирается так, чтобы output оставался далеко от max_tokens (по опыту — 20–50 строк на вызов в зависимости от "многословности" схемы).
- **Минимальный JSON**: без пролога/пояснений/pretty-print с большими отступами, строго `[{...}, {...}]`, атомарные поля.
- **Temperature=0**, один канонический example-row (не несколько — путают модель на длинных выводах), явный запрет "extra keys"/"same as above".
- **Детерминированный merge и валидация постфактум кодом**, не LLM: сверка ожидаемого/фактического числа строк, типов, уникальности ключей; аномальные диапазоны (много null подряд) — на переизвлечение точечно, а не всего документа.
- Референсные пайплайны (для идей, не для прямого внедрения):
  - **Unstructured.io** — broad ETL: partition (Auto/Fast/High Res/**VLM**-стратегия, например Gemini 2.0 Flash как VLM) → элементы (Table/Image/NarrativeText + bbox/metadata) → enrichment (Table Description, **Table→HTML** в Standard или **Agentic**-режиме для сложных таблиц, NER, Generative OCR) → chunking (by Title/Page/Similarity) → dest (vector store/DB). Self-hostable, широкий охват форматов. [docs.unstructured.io/.../table-extraction-from-pdf](https://docs.unstructured.io/examplecode/codesamples/apioss/table-extraction-from-pdf)
  - **LlamaParse** — один сильный мультимодальный LLM-парсер, заточен именно под layout-verное извлечение (сложные таблицы, merged cells, многоуровневые заголовки), выход — Markdown/JSON-parse-tree, tight-интеграция с LlamaIndex. Только hosted (LlamaCloud) на 2026 год — для вашего кейса (данные ЕИС, потенциально контрактная/коммерческая информация) это означает трансграничную передачу данных, что стоит явно учитывать при выборе. [learn.engineering.vips.edu/compare/unstructured-io-vs-llama-parse](https://learn.engineering.vips.edu/compare/unstructured-io-vs-llama-parse)
  - Практический паттерн 2025–2026: **router** — "обычные" PDF идут через самохостимый пайплайн (или прямой вызов Gemini structured output), "сложные" таблицы (много merged cells, финансовая точность критична) — эскалируются на более дорогой/точный путь (или на second-pass с более сильной моделью/более мелким чанком).

Для вашего конкретного кейса (сметы, сотни строк, регламентированный формат) прагматичная реализация на стадии 4 (`EstimateAnalysis`/`OrderParsing`):
1. Pass 1 (дешёвая модель или таргетированный prompt): детект границ таблицы(-иц) и число строк/колонок (уже частично решается на стадии 3 AI Screening).
2. Pass 2: извлечение по row-range чанкам с явными row_index, строгой JSON schema (structured output через Gemini `response_schema`/Prism `ObjectSchema`).
3. Merge + детерминированная валидация (суммы сходятся, типы, дубликаты) в PHP-коде, не в LLM.
4. При аномалиях — точечный re-run только по подозрительному диапазону, не по всему документу (экономия токенов + латентность).

## 5. Eval/shadow-режим для сравнения провайдеров на extraction-задачах

**Метрики** (стандартный подход для extraction, не PHP-специфичный):
- **Field-level precision/recall/F1** — на уровне пары (row, field): среди непустых предсказаний, сколько точно совпало с gold (precision); среди gold-значений, сколько модель угадала (recall).
- **Row coverage** — доля строк, где модель попыталась заполнить все обязательные поля (полезно при разрешённом "abstain").
- **Row exact-match** — доля строк, где абсолютно все поля верны (жёсткая метрика "можно ли авто-одобрить строку без ревью человека").
- **Row partial accuracy** — средняя доля верных полей на строку.
- Для длинных текстовых полей — **ANLS** (Average Normalized Levenshtein Similarity) вместо строгого equality, чтобы не штрафовать за тривиальные расхождения.
- Confidence-aware пайплайны — recall при фиксированном precision (`recall@99%precision`) как единая метрика для CI-гейта. [aievals.co/task-types/document-understanding](https://www.aievals.co/task-types/document-understanding)

**Golden dataset**: 100–300 репрезентативных документов с 5–20 полями, версионируется в git вместе со схемой и annotation guidelines, никогда не правится in-place без бампа версии.

**Инструменты — в PHP/Laravel экосистеме готового "Promptfoo для Laravel" нет**, это не удалось найти. Реалистичные пути:
1. **Самописный** golden-раннер: таблица `golden_datasets` в БД (или JSON/CSV в git) → артизан-команда прогоняет текущий extraction-пайплайн по голдену → считает field/row-метрики → JSON + markdown-отчёт → CI-гейт (GitHub Actions, fail при регрессии сверх допуска на поле). Это наиболее органично ложится в ваш стек (Laravel + Docker + `make test-preflight`).
2. **Внешние LLM-eval/observability платформы**, к которым Laravel подключается по HTTP (Guzzle), без необходимости держать Python/Node в основном пути:
   - **Langfuse** — логирование трейсов (prompt/provider/params/response/latency/cost), prompt-версионирование, дэшборды сравнения моделей; хорошо ложится на "shadow mode" (primary-вызов → ответ юзеру, secondary-вызовы в очереди → лог как отдельные трейсы с `experiment_id`).
   - **DeepEval** (Python, pytest-like) — для строгих CI-тестов на промпты/схемы, но потребует Python-сайдкар за HTTP.
   - **Agenta / Opik / LangWatch** — playground + side-by-side сравнение моделей, полезны для агентных/multi-step воркфлоу, но добавляют отдельный сервис в инфраструктуру.
3. **Shadow mode архитектурно**: primary-провайдер отвечает пользователю/пишет в БД синхронно; secondary(-ые) провайдеры запускаются в queued job (Horizon), результат и метрики пишутся в отдельную таблицу с `experiment_id`/`provider`/`model`/`prompt_version` — это прямое продолжение уже принятой в проекте Jobs-модели (см. `.ai/guidelines/architecture.md`, п. Transaction & Jobs Policy).

Явно не удалось подтвердить: существование зрелого open-source PHP-пакета специально под extraction-eval (field/row-coverage метрики "из коробки") — с высокой вероятностью такого пакета просто нет, нужно писать самим поверх golden-датасета и стандартных метрик.

## Рекомендации для ТЗ

1. **Провайдер-абстракция — писать собственный тонкий контракт `LlmProvider`, а не тащить `laravel/ai` или Prism как прямую зависимость домена.** Оба пакета — 0.x, для greenfield-системы с десятилетним горизонтом и десятками млн записей зависеть от их API напрямую в доменном коде — риск. Обе библиотеки используются **как транспортный слой под капотом** одной реализации `LlmProvider`.

2. **В качестве базовой реализации — Prism, не laravel/ai**, по трём причинам:
   - context caching Gemini **официально задокументирован** с рабочим примером кода и явно описанными ограничениями API (несовместимость `cachedContent` с `system_instruction`/`tools` в одном запросе) — у `laravel/ai` это подтверждено только вторичным источником;
   - более широкий и предсказуемый механизм `base_url`-переопределения для локальных vLLM/Ollama, включая runtime-override конфига на уровне вызова — критично для будущих площадок и потенциального on-prem;
   - более зрелая и провайдер-агностичная система Schema (`AnyOfSchema`, числовые constraints, `nullable`) — прямо ложится на схему для сметных таблиц.
   - Минус: fluent API менее "Laravel-native", чем Agent-паттерн `laravel/ai` — компенсируется тем, что вы всё равно прячете Prism за собственным контрактом.

3. **Что точно придётся дописывать поверх Prism**:
   - Управление жизненным циклом Gemini-кэшей единым сервисом (list/refresh TTL/delete) — Prism даёт только `cache()`-создание и передачу `cachedContentName`, полного CRUD нет.
   - Явный row-range chunking оркестратор для больших таблиц смет (сам Prism/Gemini этого не делают — это ваш доменный Processor на стадии `EstimateAnalysis`).
   - golden-dataset раннер и field/row-level метрики — писать самим (Artisan-команда + таблица `estimate_extraction_evals` + CI-гейт), готового PHP-инструмента под это нет.
   - Shadow-режим для сравнения провайдеров — тонкий слой поверх Horizon-jobs, логирующий secondary-вызовы в отдельную таблицу; опционально подключить Langfuse по HTTP для дэшбордов, не встраивая его в критический путь.

4. **Эскиз контракта `LlmProvider`** (доменный, provider-agnostic, Prism/laravel/ai/прямой Gemini-клиент — взаимозаменяемые адаптеры за фасадом):

```php
interface LlmProvider
{
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse;

    public function completeStructured(
        LlmPrompt $prompt,
        JsonSchema $schema,
        LlmOptions $options,
    ): LlmStructuredResponse;

    public function stream(LlmPrompt $prompt, LlmOptions $options): Generator; // yields LlmDelta, финальный — LlmUsage

    // Context caching — не у всех провайдеров, поэтому отдельный опциональный контракт
}

interface SupportsContextCaching
{
    public function createCache(CacheableContent $content, LlmCacheOptions $options): LlmCacheHandle;
    public function refreshCache(LlmCacheHandle $handle, CarbonInterval $ttl): void;
    public function deleteCache(LlmCacheHandle $handle): void;
}
```

`LlmOptions` несёт `provider`, `model`, `temperature`, `maxTokens`, опционально `cacheHandle`. `LlmStructuredResponse` — `$data` (typed array/DTO) + `$usage` (`promptTokens`, `completionTokens`, `cacheHitTokens`) + `$raw`. Конкретные адаптеры (`PrismGeminiProvider`, `PrismOllamaProvider`, будущий `LaravelAiProvider`) реализуют интерфейс и живут в `app/Services/Llm/` — по аналогии с уже существующими `BrowserService`/`GeminiService` в архитектуре проекта.

---

**Не удалось подтвердить строго (пометить как риск при закладке в ТЗ):**
- Точный номер версии `laravel/ai`, где появилась поддержка `cachedContent` (указано v0.6.8 по вторичному источнику laravelmagazine.com) — перепроверить по официальному changelog github.com/laravel/ai перед решением.
- Управление TTL/удаление кэшей в Prism — по документации явно не описано, возможно появилось в версиях после ревью.
- Реальная production-эксплуатация Prism/laravel/ai на нагрузке "десятки млн записей" — оба источника молчат про масштаб, только про API-дизайн; нагрузочное поведение (retry/backoff/rate-limit на Gemini) стоит проверить отдельным PoC до фиксации в архитектуре.