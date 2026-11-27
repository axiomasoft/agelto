<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/**
 * Причина остановки генерации LLM (research/E §2,§6). Обязательное поле `LlmResponse`/
 * `LlmStructuredResponse` — валидационный каскад гейтит `MaxTokens` ДО json_decode,
 * т.к. structured output не гарантирует валидность JSON при обрезании вывода.
 */
enum FinishReason: string
{
    case Stop = 'stop';
    case MaxTokens = 'max_tokens';
    case Safety = 'safety';
    case Other = 'other';
}
