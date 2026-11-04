<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;

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
