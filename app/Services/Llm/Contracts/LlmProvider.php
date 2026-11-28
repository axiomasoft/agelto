<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Dto\Llm\Execution\LlmOptions;
use App\Dto\Llm\Execution\LlmResponse;
use App\Dto\Llm\Execution\LlmStructuredResponse;
use App\Dto\Llm\Prompt\JsonSchema;
use App\Dto\Llm\Prompt\LlmPrompt;

/**
 * Контракт транспорта LLM (ТЗ 04 §2). Домен зависит только от этого интерфейса, не от Prism/
 * laravel/ai напрямую — конкретный пакет остаётся сменяемым адаптером за
 * `app/Services/Llm/Providers/*`.
 */
interface LlmProvider
{
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse;

    public function completeStructured(
        LlmPrompt $prompt,
        JsonSchema $schema,
        LlmOptions $options,
    ): LlmStructuredResponse;
}
