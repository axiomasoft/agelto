<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Параметры одного вызова провайдера (ТЗ 04 §2). `cacheHandle` — ЕДИНСТВЕННОЕ место, где живёт
 * cache-hint (D14): интерпретация — на адаптере (Gemini → `cachedContentName`, Anthropic →
 * `cache_control`, OpenAI → `prompt_cache_key`, vLLM → no-op). `provider`/`model` — идентификаторы
 * маршрута (`prc_ai_providers`/`prc_ai_routes`), не сами объекты — контракт не зависит от Eloquent.
 */
final readonly class LlmOptions
{
    public function __construct(
        public string $provider,
        public string $model,
        public float $temperature,
        public int $maxTokens,
        public ?LlmCacheHandle $cacheHandle = null,
    ) {}
}
