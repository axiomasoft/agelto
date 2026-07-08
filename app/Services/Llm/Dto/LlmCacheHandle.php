<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Carbon\CarbonImmutable;

/**
 * Ссылка на explicit context cache провайдера (`SupportsContextCaching`, ТЗ 04 §2.1). `id` —
 * provider-специфичный идентификатор (Gemini: `cachedContents/xxx`).
 */
final readonly class LlmCacheHandle
{
    public function __construct(
        public string $id,
        public CarbonImmutable $expiresAt,
    ) {}
}
