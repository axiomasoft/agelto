<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Dto\Llm\Requests\GenerationResponse;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\AuthHeaderScheme;
use App\Enums\Llm\OperationPurpose;
use App\Services\Llm\Contracts\ProviderWireCodec;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\OpenAiStrictSchemaCompiler;

/** Narrow codec for OpenAI & OpenAI-compatible Chat Completions API. No HTTP, retry or mutable state. */
final readonly class OpenAiChatCompletionsClient implements ProviderWireCodec
{
    public function __construct(
        private string $defaultOrigin = 'https://api.openai.com',
        private ?OpenAiStrictSchemaCompiler $schemaCompiler = null,
    ) {}

    public function compileGeneration(ProviderOperationPlan $plan, string $origin): ProviderWireRequest
    {
        $resolvedOrigin = rtrim($origin !== '' ? $origin : $this->defaultOrigin, '/');
        $context = $plan->context;
        $input = $plan->generation;
        if ($plan->identity->purpose !== OperationPurpose::Generate || $input === null
            || preg_match('/^v[0-9]+(?:alpha|beta)?$/D', $context->apiVersion) !== 1
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/:-]*$/D', $context->model) !== 1
            || array_diff(array_keys($context->options), ['max_output_tokens', 'temperature']) !== []
            || array_diff(array_keys($context->thinking), ['effort', 'level']) !== []) {
            throw new \InvalidArgumentException('Unsupported pinned OpenAI operation/options.');
        }
        $max = $context->options['max_output_tokens'] ?? null;
        $temperature = $context->options['temperature'] ?? null;
        if (! is_int($max) || $max < 1
            || ($temperature !== null && ((! is_string($temperature) && ! is_int($temperature))
                || ! is_numeric((string) $temperature) || (float) $temperature < 0 || (float) $temperature > 2))) {
            throw new \InvalidArgumentException('Invalid pinned OpenAI generation bounds.');
        }
        $effort = $context->thinking['effort'] ?? $context->thinking['level'] ?? null;
        if ($effort !== null && (! is_string($effort) || ! in_array($effort, ['minimal', 'low', 'medium', 'high', 'xhigh'], true))) {
            throw new \InvalidArgumentException('Invalid pinned OpenAI reasoning effort.');
        }

        $taskName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $input->purpose->value) ?: 'structured_output';
        $taskName = substr($taskName, 0, 64);

        $compiler = $this->schemaCompiler ?? new OpenAiStrictSchemaCompiler;
        $payload = [
            'model' => $context->model,
            'messages' => [
                ['role' => 'system', 'content' => $input->system->content],
                ['role' => 'user', 'content' => $input->user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $taskName,
                    'strict' => true,
                    'schema' => $compiler->strictify($input->schema->definition),
                ],
            ],
            'max_completion_tokens' => $max,
        ];
        if ($temperature !== null) {
            $payload['temperature'] = (float) $temperature;
        }
        if ($effort !== null) {
            $payload['reasoning_effort'] = $effort;
        }

        return new ProviderWireRequest(
            operation: $plan,
            method: 'POST',
            endpoint: $resolvedOrigin.'/'.$context->apiVersion.'/chat/completions',
            allowedOrigin: $resolvedOrigin,
            body: CanonicalJson::encode($payload),
            capabilities: $input->capabilities,
            authScheme: AuthHeaderScheme::Bearer,
        );
    }

    public function decodeGeneration(VerifiedReceipt $receipt): GenerationResponse
    {
        $value = json_decode($receipt->body, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            return new GenerationResponse('', 'UNSUPPORTED', true);
        }
        $choices = $value['choices'] ?? null;
        if (! is_array($choices) || ! array_is_list($choices) || $choices === [] || count($choices) > 1) {
            return new GenerationResponse('', 'UNSUPPORTED', true);
        }
        $choice = $choices[0];
        if (! is_array($choice) || ! is_array($choice['message'] ?? null)) {
            return new GenerationResponse('', 'UNSUPPORTED', true);
        }
        $message = $choice['message'];
        if (isset($message['tool_calls']) && $message['tool_calls'] !== []) {
            return new GenerationResponse('', 'UNSUPPORTED', true);
        }
        if (is_string($message['refusal'] ?? null) && $message['refusal'] !== '') {
            return new GenerationResponse('', 'REFUSAL');
        }
        $rawFinish = $choice['finish_reason'] ?? null;
        $finishReason = match ($rawFinish) {
            'stop' => 'STOP',
            'length' => 'MAX_TOKENS',
            'content_filter' => 'SAFETY',
            default => 'UNSUPPORTED',
        };
        $content = $message['content'] ?? null;
        if (! is_string($content)) {
            return new GenerationResponse('', $finishReason, true);
        }

        return new GenerationResponse($content, $finishReason);
    }

    public function isUncachedGenerationBody(string $body): bool
    {
        return true;
    }
}
