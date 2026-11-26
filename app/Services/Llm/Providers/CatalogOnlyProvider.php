<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Exceptions\Llm\UnsupportedConsumer;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;

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
