<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Carbon\CarbonImmutable;

/**
 * Одна строка ответа источника расхода (`root/usage-source-contract.md` §2). Ось, не выбранная
 * запросом, приходит как `null` — «схлопнута», а не «неизвестна». Денег-производных (рубли,
 * экономия кэша) строка не несёт: их считает потребитель.
 */
final readonly class UsageRow
{
    public function __construct(
        public ?CarbonImmutable $day,
        public ?string $provider,
        public ?string $model,
        public ?string $purpose,
        public int $calls,
        public int $promptTokens,
        public int $cachedTokens,
        public int $completionTokens,
        public int $costMicros,
    ) {}
}
