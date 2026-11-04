<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use App\Enums\Llm\FinishReason;

/**
 * Ответ `LlmProvider::complete` — нетипизированный текстовый вызов (ТЗ 04 §2). `raw` — сырой
 * текст ответа провайдера (запись в object storage — забота `LlmCallRecorder`, не этого DTO).
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $data,
        public LlmUsage $usage,
        public FinishReason $finishReason,
        public string $raw,
    ) {}
}
