---
name: llm-layer-dev
description: "Dev-раннбук LLM-транспорта: контракт LlmProvider, добавление провайдера-адаптера, кэш-инварианты (prefix-stable/capability-based), capability-матрица (transport-лимиты + EOL-модели/преемники), учёт стоимости, запреты (реальные вызовы в тестах, Gemini-специфика в домене, indirect prompt injection). Активировать при правках в Services/Llm/**, config/ai.php, добавлении нового провайдера/модели."
paths:
  - "app/Services/Llm/**"
  - "config/ai.php"
---

# LLM Layer — dev-раннбук

Транспортный слой LLM-вызовов (`app/Services/Llm/**`), провайдер-агностичный (D8/D14/D16) — домен
(`app/Services/Procurement/{Screening,Extraction}/**`) зависит ТОЛЬКО от `Contracts\LlmProvider`,
никогда от Prism/Gemini/vLLM напрямую. Смежное: skill `estimate-extraction-dev` (потребитель),
`prompt-engineering-agelto` (что уходит в промт).

## 1) Контракт `LlmProvider` (P3.1)

```php
interface LlmProvider {
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse;
    public function completeStructured(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): LlmStructuredResponse;
}
```

Опциональные расширения — отдельные интерфейсы, домен проверяет `instanceof` перед использованием
(не все провайдеры их реализуют):

- `SupportsFileUpload` (`uploadFile()`) — Files API (Gemini). `$mimeType` обязателен — не угадывать.
- `SupportsContextCaching` (`createCache()`/`refreshCache()`/`deleteCache()`) — explicit context
  cache (Gemini `cachedContents`). OpenAI/vLLM его не реализуют (полагаются на implicit кэш) — это
  ОЖИДАЕМО, не пробел.

## 2) Добавление провайдера-адаптера

Смотри `Providers/PrismGeminiProvider.php` (эталон-реализация с Files API/кэшем) и
`Providers/OpenAiCompatibleProvider.php` (эталон минимальной реализации, structured-only, без
Files API — ограничение честно бросает исключение, а не тихо роняет файл). Чек-лист нового
адаптера:

1. Реализуй `LlmProvider` минимум; `SupportsFileUpload`/`SupportsContextCaching` — только если
   провайдер их реально поддерживает.
2. `$fileRefs` в `LlmPrompt` непусты, а адаптер Files API не поддерживает — брось исключение
   явно, не отправляй документ как обычный текст молча (см. `OpenAiCompatibleProvider`).
3. `LlmUsage.promptTokens` — БЕЗ `cachedTokens` (последние — отдельное поле `cachedTokens`,
   единая семантика для всех адаптеров, независимо от того, как конкретный API считает токены
   внутри себя — сверься с докблоком `PrismGeminiProvider` про расхождение Prism-хендлеров).
4. Structured output — через `JsonSchema`/`responseJsonSchema` (P2.3), без ручной пересборки
   в DSL конкретного SDK, если пакет-транспорт поддерживает raw-schema режим.
5. Заведи маршрут в `config('ai.capabilities.<model>')` (§4) и `llm_providers`/`llm_routes`
   (сид, см. `AiRoutingSeeder`) — без capability-записи preflight извлечения (`ExtractionPreflight`)
   не сможет выбрать путь.
6. Тестовый двойник — не пиши свой, используй/расширяй `Tests\Support\Doubles\FakeLlmProvider`.

## 3) Кэш-инварианты (D8/D14)

- Промт ВСЕГДА prefix-stable — системная часть детерминирована (`PromptComposer::compose()`),
  это база implicit-кэша ЛЮБОГО провайдера. Не вставляй недетерминированный контент (текущее
  время, случайный ID) в системную часть.
- Explicit cache — деталь АДАПТЕРА, не домена (`LlmCacheManager` принимает `LlmProvider`
  параметром, проверяет `instanceof SupportsContextCaching`). Ошибка похода к провайдеру за
  кэшем — warning в лог, НЕ exception: implicit-путь всегда доступен как fallback.
- `usage.cachedTokens` обязателен в ответе КАЖДОГО адаптера (для `LlmCallRecorder`/`ai:cost-report`).
- Gemini-специфика (`cachedContent` несовместим с `system_instruction`/`tools` в одном запросе —
  см. `CacheableContent`) остаётся ВНУТРИ `PrismGeminiProvider`, не течёт в контракт/домен.

## 4) Capability-матрица (`config('ai.capabilities.<model>')`, D14/D27/D29)

Одна запись на модель несёт: цены (`input_per_1m`/`output_per_1m`/`cached_per_1m`) + транспортные
лимиты (`max_request_file_mb`, `max_pages`, `supports_files_api`) + lifecycle (`eol_date`,
`successor`). Перед пином нового `model` ID в маршрут — **сверь `eol_date`/deprecation schedule
провайдера**, не только цену (v1-урок, research L finding 1: план едва не повторил пин
устаревающей линейки). `model_eol_warn_days` + `pipeline:stuck-check` (P7.3) алертят по
приближению активного маршрута к `eol_date` — не удаляй эту связь при рефакторинге.

Транспортные лимиты — не справочная информация, а вход `ExtractionPreflight` (preflight ДО
upload, D29): без `max_request_file_mb`/`max_pages` на новой модели extraction для больших
документов не сможет выбрать `windowed`-путь и будет молча падать на реальном лимите провайдера.

## 5) Стоимость

`LlmCallRecorder` пишет КАЖДЫЙ вызов (успех и неуспех) в `llm_calls`, `cost_micros` считает
из `capabilities.*` (доллары × 1e6). `ai:cost-report` — отчёт по окну; `AI_COST_REPORT_RUB_PER_USD`
— статический курс env, не live-котировка (сознательный YAGNI, не баг).

## 6) Запреты — дословно

- **Никаких прямых вызовов Prism/конкретного SDK из домена** (`Services/Procurement/**`,
  `Jobs/**`) — только через `LlmProvider`.
- **Никакой Gemini-специфики в контрактах** (`Contracts/`, `Dto/`) — `cachedContent`/`Files API`
  детали живут в `Providers/PrismGeminiProvider.php`, не просачиваются в интерфейсы/DTO уровня
  контракта.
- **Реальные LLM-вызовы в тестах запрещены** — только `Tests\Support\Doubles\FakeLlmProvider`
  (см. skill `testing-rules`).
- **Indirect prompt injection (research/L finding 14, `docs/specs/v2/08-estimate-extraction.md`
  §7):** содержимое документа закупки — untrusted input (грузит третья сторона, не проверяется
  до отправки в LLM). Для ВСЕХ extraction-вызовов (`extract_*`/`extraction:discovery`):
  - вызовы идут БЕЗ tools/function-calling — модели нечем исполнить встроенную в документ
    инструкцию;
  - системные правила НЕ переопределяются текстом документа ни при каких обстоятельствах —
    `system_instruction` синтаксически отделён от `contents`, не конкатенация в одном блоке;
  - подозрительные инструкции внутри документа НЕ исполняются, а флагируются в ответ (аномалия
    для операторского ревью), не тихо игнорируются и не пропускаются как есть.

## 7) Test-gate

```bash
docker compose exec app php artisan test --compact --filter=Llm
```
