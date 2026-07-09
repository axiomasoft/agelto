---
audience: dev
type: howto
status: draft
---

# Типовые задачи

## Поменять/добавить текст промта

1. Найти/создать файл в `resources/ai/prompts/<step>/` — структура и правила описаны в
   `resources/ai/prompts/README.md` (единственная инструкция, не дублируется здесь).
2. Если это правка существующего активного промта — поднять `version` (не редактировать текст на
   месте у уже использованной версии, иначе теряется воспроизводимость `rules_hash`).
3. `make artisan ARGS="ai:prompts:sync --check"` — сверить дрейф файлы↔БД перед деплоем;
   `ai:prompts:sync` (без `--check`) на деплое, либо `db:seed` (`PromptSeeder`).
4. Прогнать `ai:eval:run --task=extraction` (если датасет заведён) — убедиться, что правка не
   уронила метрики. Подробнее — `03_Dev/ai/prompts.md`, `03_Dev/ai/training-loop.md`.

## Добавить нового LLM-провайдера (например, локальный vLLM)

1. Реализовать `App\Services\Llm\Contracts\LlmProvider` (и опционально `SupportsFileUpload`/
   `SupportsContextCaching`, если провайдер их поддерживает) — новый класс в
   `app/Services/Llm/Providers/`. `OpenAiCompatibleProvider` уже покрывает любой OpenAI-протокольный
   бэкенд (vLLM/Ollama) через `base_url`/`api_key` — часто отдельный класс не нужен, достаточно
   новой записи в `prc_ai_providers`.
2. Добавить провайдера в `prc_ai_providers`, маршрут(ы) в `prc_ai_routes` (driver, capability-запись
   в `config('ai.capabilities.<model>')` — цены, лимиты, `eol_date`/`successor` при наличии).
3. Убедиться, что адаптер отдаёт `LlmUsage.cachedTokens` в ответе (обязательное поле контракта, D14)
   — даже `0`, если кэш не поддерживается.
4. Подробнее об архитектуре роутинга/адаптеров — `03_Dev/llm-providers.md`.

## Добавить новый тип документа (второй экстрактор, не смета)

1. Результат хранится как `jsonb` в `prc_ai_analyses.result` — generic-таблица под него НЕ
   создаётся заранее (ADR [`adr/0004-jsonb-for-non-estimate-results.md`](adr/0004-jsonb-for-non-estimate-results.md)).
2. Новый `document_type` в роутинге (`prc_ai_routes`) и, если нужен отдельный набор промтов — новый
   `PromptStep`-кейс + файлы в `resources/ai/prompts/`.
3. Новый Data-контракт ответа (схема-как-код, D2) — генерирует и промт-исходник, и
   `responseJsonSchema` из одного PHP-класса; см. `03_Dev/ai/prompts.md` §Composite versioning.
4. Второй экстрактор — не копия `ExtractionOrchestrator`, а собственный оркестратор своей стадии
   (реюз ChunkSource/checkpoint-механики — по применимости, не обязателен).

## Смежное

- `03_Dev/troubleshooting.md` — если рецепт не сработал так, как ожидалось.
- `03_Dev/document-pipeline.md`/`llm-providers.md` — устройство пайплайна целиком.
