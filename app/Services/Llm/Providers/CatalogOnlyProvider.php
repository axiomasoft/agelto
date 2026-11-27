<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Dto\Llm\Execution\LlmOptions;
use App\Dto\Llm\Execution\LlmResponse;
use App\Dto\Llm\Execution\LlmStructuredResponse;
use App\Dto\Llm\Prompt\JsonSchema;
use App\Dto\Llm\Prompt\LlmPrompt;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Services\Llm\Contracts\LlmProvider;

/** Catalog-only adapter. Billable generation requires StructuredCallExecutor and an owning gate. */
final readonly class CatalogOnlyProvider implements LlmProvider
{
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse
    {
        throw new UnsupportedConsumer;
    }

    public function completeStructured(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): LlmStructuredResponse
    {
        throw new UnsupportedConsumer;
    }
}
