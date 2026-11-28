---
audience: dev
type: howto
status: living
---

# Типовые задачи

## Поменять/добавить текст промта

1. Найти/создать файл в одном из двух корней канона (`resources/ai/prompts/<step>/` — кросс-документные
   группы, `resources/<домен>/ai/prompts/<step>/` — группы типа-владельца, D32) — структура и правила
   описаны в `resources/ai/prompts/README.md` (единственная инструкция, не дублируется здесь).
2. Если это правка существующего активного промта — поднять `version` (не редактировать текст на
   месте у уже использованной версии, иначе теряется воспроизводимость `rules_hash`).
3. `make artisan ARGS="ai:prompts:sync --check"` — сверить дрейф файлы↔БД перед деплоем;
   `ai:prompts:sync` (без `--check`) на деплое, либо `db:seed` (`PromptSeeder`).
4. Прогнать `estimate:eval:run --task=extraction` (если датасет заведён) — убедиться, что правка не
   уронила метрики. Подробнее — `dev/ai/prompts.md`, `dev/ai/training-loop.md`.

## Добавить нового LLM-провайдера (например, локальный vLLM)

1. Реализовать `App\Services\Llm\Contracts\LlmProvider` (и опционально `SupportsFileUpload`/
   `SupportsContextCaching`, если провайдер их поддерживает) — новый класс в
   `app/Services/Llm/Providers/`. `OpenAiCompatibleProvider` уже покрывает любой OpenAI-протокольный
   бэкенд (vLLM/Ollama) через `base_url`/`api_key` — часто отдельный класс не нужен, достаточно
   новой записи в `llm_providers`.
2. Добавить провайдера в `llm_providers`, маршрут(ы) в `llm_routes` (driver, capability-запись
   в `config('ai.capabilities.<model>')` — цены, лимиты, `eol_date`/`successor` при наличии).
3. Убедиться, что адаптер отдаёт `LlmUsage.cachedTokens` в ответе (обязательное поле контракта, D14)
   — даже `0`, если кэш не поддерживается.
4. Подробнее об архитектуре роутинга/адаптеров — `dev/llm-providers.md`.

## Добавить новый тип документа (второй экстрактор, не смета)

1. Результат хранится как `jsonb` в `prc_ai_analyses.result` — generic-таблица под него НЕ
   создаётся заранее (ADR [`adr/0004-jsonb-for-non-estimate-results.md`](adr/0004-jsonb-for-non-estimate-results.md)).
2. Новый `document_type` в роутинге (`llm_routes`) и, если нужен отдельный набор промтов — новый
   типовой enum шага, реализующий `App\Enums\Llm\PromptStep` (`App\Enums\Procurement\Analyzers\
   &lt;Type&gt;\*PromptStep`, contract P2.1 §4.1) + свой каталог `resources/<домен>/ai/prompts/**` (D32).
3. Новый Data-контракт ответа (схема-как-код, D2) — из одного PHP-класса генерируется и
   `response_schema` (структура/`description`/порядок), и словарь синонимов `#[Alias]`,
   печатаемый в системную часть; см. `dev/ai/prompts.md` §Composite versioning.
4. Второй экстрактор — не копия `ExtractionOrchestrator`, а собственный оркестратор своей стадии
   (реюз ChunkSource/checkpoint-механики — по применимости, не обязателен).

## Смежное

- `dev/troubleshooting.md` — если рецепт не сработал так, как ожидалось.
- `dev/document-pipeline.md`/`llm-providers.md` — устройство пайплайна целиком.
