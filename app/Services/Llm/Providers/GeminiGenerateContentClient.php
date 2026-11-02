<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Enums\Llm\OperationPurpose;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Schema\CanonicalJson;

/** Narrow codec only. No SDK, HTTP, route/config lookup, retry or mutable client. */
final readonly class GeminiGenerateContentClient
{
    public function __construct(private string $origin = 'https://generativelanguage.googleapis.com') {}

    public function compile(ProviderOperationPlan $plan): ProviderWireRequest
    {
        $context = $plan->context;
        $input = $plan->generation;
        if ($plan->identity->purpose !== OperationPurpose::Generate || $input === null
            || preg_match('/^v1(?:beta)?$/D', $context->apiVersion) !== 1
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $context->model) !== 1
            || array_diff(array_keys($context->options), ['max_output_tokens', 'temperature']) !== []
            || array_diff(array_keys($context->thinking), ['budget', 'level', 'include_thoughts']) !== []) {
            throw new \InvalidArgumentException('Unsupported pinned Gemini operation/options.');
        }
        $max = $context->options['max_output_tokens'] ?? null;
        $temperature = $context->options['temperature'] ?? null;
        if (! is_int($max) || $max < 1 || $temperature !== null && (! is_string($temperature) || ! is_numeric($temperature) || (float) $temperature < 0 || (float) $temperature > 2)) {
            throw new \InvalidArgumentException('Invalid pinned generation bounds.');
        }
        $thinking = [];
        foreach (['budget' => 'thinkingBudget', 'level' => 'thinkingLevel', 'include_thoughts' => 'includeThoughts'] as $source => $target) {
            if (array_key_exists($source, $context->thinking)) {
                $value = $context->thinking[$source];
                $valid = match ($source) {
                    'budget' => is_int($value) && $value >= -1,
                    'level' => is_string($value) && in_array($value, ['minimal', 'low', 'medium', 'high'], true),
                    'include_thoughts' => is_bool($value),
                };
                if (! $valid) {
                    throw new \InvalidArgumentException('Invalid pinned thinking option.');
                }
                $thinking[$target] = $value;
            }
        }
        if (isset($thinking['thinkingBudget'], $thinking['thinkingLevel'])) {
            throw new \InvalidArgumentException('Thinking budget and level are mutually exclusive.');
        }
        $config = ['maxOutputTokens' => $max, 'responseMimeType' => 'application/json', 'responseJsonSchema' => $input->schema->definition];
        if ($temperature !== null) {
            $config['temperature'] = (float) $temperature;
        }
        if ($thinking !== []) {
            $config['thinkingConfig'] = $thinking;
        }

        return new ProviderWireRequest(operation: $plan, method: 'POST',
            endpoint: $this->origin.'/'.$context->apiVersion.'/models/'.$context->model.':generateContent', allowedOrigin: $this->origin,
            body: CanonicalJson::encode(['systemInstruction' => ['parts' => [['text' => $input->system->content]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $input->user]]]], 'generationConfig' => $config]));
    }
}
