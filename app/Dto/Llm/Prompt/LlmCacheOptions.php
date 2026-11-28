<?php

declare(strict_types=1);

namespace App\Dto\Llm\Prompt;

use Carbon\CarbonInterval;

/**
 * Параметры создания explicit context cache (`SupportsContextCaching::createCache`).
 */
final readonly class LlmCacheOptions
{
    public function __construct(
        public string $model,
        public CarbonInterval $ttl,
    ) {}
}
