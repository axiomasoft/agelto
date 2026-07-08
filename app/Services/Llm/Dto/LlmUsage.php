<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Учёт токенов одного вызова (ТЗ 04 §2, §6). `cachedTokens` — отдельное поле (не подмножество
 * `promptTokens`) — обязателен для расчёта `cost_micros` по кэш-тарифу (P3.2) и для метрик
 * экономии на implicit/explicit caching.
 */
final readonly class LlmUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $cachedTokens,
        public int $totalTokens,
    ) {}
}
