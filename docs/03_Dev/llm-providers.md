---
audience: dev
type: explanation
status: draft
---

# LLM-провайдеры

Транспортный LLM-слой — `App\Services\Llm\**`. Контракт `LlmProvider` не завязан на конкретного
вендора (D14): сегодня Gemini через Prism (`PrismGeminiProvider`), OpenAI-совместимый адаптер
(`OpenAiCompatibleProvider`) — задел под vLLM/Ollama (локальная LLM, `docs/03_Dev/ai/training-loop.md`)
и любой OpenAI-протокольный провайдер без изменения домена.

## Д6 — LLM-слой: роутинг и адаптеры

```mermaid
flowchart TB
    Caller["ScreeningProcessor / ExtractionOrchestrator"]
    Resolver["LlmRouteResolver::resolve(documentType, purpose)<br/>(app/Services/Llm/LlmRouteResolver.php:40)"]
    Routes[("llm_routes<br/>priority ASC, activeCandidatesFor()")]
    Resolved["ResolvedRoute(provider, LlmOptions, route)"]
    Gemini["PrismGeminiProvider<br/>(app/Services/Llm/Providers/PrismGeminiProvider.php)"]
    OpenAiCompat["OpenAiCompatibleProvider<br/>(app/Services/Llm/Providers/OpenAiCompatibleProvider.php)<br/>vLLM/Ollama, stateless base_url/api_key"]
    Contract{{"LlmProvider contract<br/>(app/Services/Llm/Contracts/LlmProvider.php)"}}
    FileUpload{{"SupportsFileUpload"}}
    Cache{{"SupportsContextCaching"}}
    Usage["LlmUsage.cachedTokens<br/>(обязателен в ответе, D14)"]

    Caller --> Resolver
    Resolver --> Routes
    Routes --> Resolved
    Resolved -->|driver=gemini| Gemini
    Resolved -->|driver=openai_compatible| OpenAiCompat
    Gemini -.implements.-> Contract
    OpenAiCompat -.implements.-> Contract
    Gemini -.implements.-> FileUpload
    Gemini -.implements.-> Cache
    Contract --> Usage
```

Capability-based кэширование (D14): промт всегда prefix-stable (`PromptComposer`); опциональный
cache-hint интерпретируется адаптером — Gemini → `cachedContents` (`SupportsContextCaching`,
частично подключён — см. Known Deviations `ExtractionOrchestrator`), OpenAI/vLLM → no-op/
автоматический prefix-cache; `usage.cachedTokens` обязателен в ответе независимо от адаптера.
`shadowRoute()` (`app/Services/Llm/LlmRouteResolver.php:69`) — теневой маршрут для сравнения
с локальной LLM (P9.4, `ShadowDispatch`).

## Роутинг

`llm_routes` хранит кандидатов `(document_type, purpose) → model`, `priority ASC` внутри группы —
`LlmRouteResolver::resolve()` берёт первый активный. Стартовый роутинг (D4, сиды `AiRoutingSeeder`):
скрининг → `gemini-2.5-flash-lite`, извлечение → `gemini-2.5-flash`, эскалация по флагу сложности
или провалу валидации → `gemini-2.5-pro`. Discovery-путь (windowed fallback, D30/D37) — отдельный
`EstimateLlmPurpose::Discovery`, свой маршрут, свой промт-шаг (`EstimatePromptStep::ExtractionDiscovery`) — НЕ
переиспользует screening-маршрут (разные роли: «что за документ» vs «где границы смет»).

## Транспорт: Prism за фасадом

Прямых вызовов `prism-php/prism` за пределами `Providers/` нет (D8/D14) — домен зависит только от
`LlmProvider`. Причина выбора Prism вместо first-party `laravel/ai` — устоявшийся typed API Gemini
(files + context caching) против 0.x/preview статуса `laravel/ai` на момент решения (research/G §1);
контракт держится адаптер-совместимым с обоими на будущее.

## Capability-матрица моделей и лимиты (D27, D29)

`config('ai.capabilities.<model>')` — не только цены и параметры кэша, но и ТРАНСПОРТНЫЕ лимиты:
`max_request_file_mb` (Gemini=50), `max_pages` (Gemini=1000), `supports_files_api`, `eol_date`,
`successor`. `ExtractionOrchestrator` делает **preflight ДО upload** (`ExtractionPreflight::decide()`)
— размер файла + число страниц (`qpdf --show-npages`) против лимитов активного маршрута → решение
целиком/окнами/`unprocessable(provider_limit_*)`. EOL-мониторинг: каждая активная модель маршрута
несёт `eol_date`; `config('ai.model_eol_warn_days')` (default 30) — порог алерта «модель маршрута
приближается к EOL» (линия 2.5 умирает 2026-10-16, преемники 3.x уже прописаны в конфиге
закомментированной строкой рядом).

**Риск EOL канала (мониторинг, не работа этой фазы).** Страница нашего канала (`generateContent`,
через который Prism шлёт `response_schema`) помечена вендором «Gemini Generate Content API
(Legacy)»; рядом — новый Interactions API с иной формой параметра (`response_format` вместо
`generationConfig.response_schema`), см. `findings/P2-vendor-structured-output.md` §9 плана
`2026.07.31-SYS-INTEGRITY`. Факт зафиксирован как вход EOL-контура (`config('ai.capabilities')`,
выше) — смена канала вендора миграцией не планируется, решение остаётся за мониторингом.

## Исходящий прокси

Домен `Llm` не знает про прокси-пул/скоупы/страны — только про узкий порт `OutboundProxy`
(`App\Services\Llm\Contracts\OutboundProxy::optionsFor(string $host): array`), реализованный
адаптером `PoolOutboundProxy` (`app/Services/Procurement/Proxy/PoolOutboundProxy.php`) со стороны
прокси-слоя: адаптер резолвит узел для скоупа `ProxyScope::Llm` через `ProxyResolver` и фолбэчит на
`config('ai.gemini.proxy')`, если пул для LLM пуст. Подробнее про сам слой маршрутизации —
`crawling-pipeline.md`.

## Учёт стоимости

`llm_calls` пишет usage (prompt/completion/cached tokens) на КАЖДЫЙ физический вызов —
`LlmCallRecorder`. `ai:cost-report {--days=7}` (`CostReportCommand`) агрегирует расход по
провайдеру/маршруту/шагу за окно; конвертация в рубли — по конфигурируемому курсу (боевой курс не
заведён — открытый вопрос плана). `ai:caches {list|prune} [--hits] [--window=]` (`CachesCommand`) —
живые/осиротевшие explicit-кэши и доля implicit cache-хитов по purpose (usage-based, не отдельная
таблица). Реальные тарифы активных маршрутов, порядок величины стоимости документа и
неоткалиброванные лимиты аккаунта — `docs-private/ai-economics.md` (не зеркалится в Brain).

## Смежное

- Как строится промт, который уходит в вызов — [`ai/prompts.md`](ai/prompts.md) (Д3).
- Feature-карточки конкретных AI-шагов — [`ai/feature-card-screening.md`](ai/feature-card-screening.md),
  [`ai/feature-card-estimate-extraction.md`](ai/feature-card-estimate-extraction.md).
- Диагностика ошибок Gemini — [`runbooks/gemini-errors.md`](runbooks/gemini-errors.md).
