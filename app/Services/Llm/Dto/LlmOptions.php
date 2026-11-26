<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Параметры одного вызова провайдера (ТЗ 04 §2). `cacheHandle` — ЕДИНСТВЕННОЕ место, где живёт
 * cache-hint: интерпретация — на адаптере (Gemini → `cachedContentName`, Anthropic →
 * `cache_control`, OpenAI → `prompt_cache_key`, vLLM → no-op). `provider`/`model` — идентификаторы
 * маршрута (`llm_providers`/`llm_routes`), не сами объекты — контракт не зависит от Eloquent.
 *
 * `LlmRouteResolver` копирует `baseUrl` и имя переменной `apiKeyEnv` из метаданных маршрута.
 * Вызовы выполняются через владельца запроса с отдельной привязкой подключения.
 *
 * `thinkingLevel` — уровень размышления линейки Gemini 3.x
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
        public ?int $requestTimeoutSeconds = null,
    ) {}
}
