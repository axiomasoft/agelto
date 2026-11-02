<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Exceptions\Llm\UnsupportedConsumer;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Contracts\OutboundEndpoints;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;

/** Explicitly unsupported until a compatible owning gate and receipt policy are registered. */
final readonly class OpenAiCompatibleProvider implements LlmProvider, OutboundEndpoints
{
    public function outboundOrigins(?string $baseUrl): array
    {
        if ($baseUrl === null || $baseUrl === '') {
            throw new \RuntimeException('OpenAI-compatible provider has no configured base URL.');
        }

        return [$baseUrl];
    }

    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse
    {
        throw new UnsupportedConsumer;
    }

    public function completeStructured(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): LlmStructuredResponse
    {
        throw new UnsupportedConsumer;
    }
}
