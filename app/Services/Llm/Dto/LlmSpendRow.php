<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Одна строка отчёта расхода (`ai:cost-report`) — агрегат за окно по `(provider, model, purpose)`
 * + посчитанная экономия explicit-кэша (`LlmSpendReporter::report()`).
 */
final readonly class LlmSpendRow
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $purpose,
        public int $calls,
        public int $promptTokens,
        public int $cachedTokens,
        public int $completionTokens,
        public int $costMicros,
        public int $savingsMicros,
    ) {}
}
