<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Dto\Llm\Prompt\CacheableContent;
use App\Dto\Llm\Prompt\LlmCacheHandle;
use App\Dto\Llm\Prompt\LlmCacheOptions;
use Carbon\CarbonInterval;

/**
 * Опциональный контракт explicit context caching (ТЗ 04 §2.1) — реализуют не все провайдеры
 * (Gemini `cachedContents`; OpenAI/vLLM полагаются на implicit/prompt-caching и его не реализуют).
 * Домен проверяет `instanceof SupportsContextCaching` перед использованием.
 */
interface SupportsContextCaching
{
    public function createCache(CacheableContent $content, LlmCacheOptions $options): LlmCacheHandle;

    public function refreshCache(LlmCacheHandle $handle, CarbonInterval $ttl): void;

    public function deleteCache(LlmCacheHandle $handle): void;
}
