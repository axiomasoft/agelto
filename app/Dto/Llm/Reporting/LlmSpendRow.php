<?php

declare(strict_types=1);

namespace App\Dto\Llm\Reporting;

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
        public ?int $savingsMicros,
        public int $unknownCalls = 0,
        public int $reservedMicros = 0,
    ) {}
}
