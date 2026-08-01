<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Параметры одного вызова провайдера (ТЗ 04 §2). `cacheHandle` — ЕДИНСТВЕННОЕ место, где живёт
 * cache-hint (D14): интерпретация — на адаптере (Gemini → `cachedContentName`, Anthropic →
 * `cache_control`, OpenAI → `prompt_cache_key`, vLLM → no-op). `provider`/`model` — идентификаторы
 * маршрута (`llm_providers`/`llm_routes`), не сами объекты — контракт не зависит от Eloquent.
 *
 * `baseUrl`/`apiKeyEnv` (P9.4) — те же самые прозрачные идентификаторы (не объекты), но для
 * `OpenAiCompatibleProvider`: `llm_providers.base_url`/`options.api_key_env` копируются сюда
 * `LlmRouteResolver`, чтобы адаптер оставался stateless (`container->make()` без параметров —
 * `app()->instance(OpenAiCompatibleProvider::class, $fake)` продолжает работать в тестах, тот же
 * приём, что `PrismGeminiProvider` в `ScreeningProcessorTest`). Gemini-адаптер оба поля игнорирует.
 *
 * `thinkingLevel` (D98/D99, P2.9) — уровень размышления линейки Gemini 3.x
 * (`MINIMAL`/`LOW`/`MEDIUM`/`HIGH`, дефолт вендора `MEDIUM`), `null` — не передавать провайдеру
 * вовсе. Носитель значения — `llm_routes.options.thinking_level`. НЕ `thinkingBudget` (рычаг
 * линейки 2.5, несовместим с `thinkingLevel` в одном запросе Gemini 3).
 */
final readonly class LlmOptions
{
    public function __construct(
        public string $provider,
        public string $model,
        public float $temperature,
        public int $maxTokens,
        public ?LlmCacheHandle $cacheHandle = null,
        public ?string $baseUrl = null,
        public ?string $apiKeyEnv = null,
        public ?string $thinkingLevel = null,
    ) {}
}
