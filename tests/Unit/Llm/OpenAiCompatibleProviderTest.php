<?php

declare(strict_types=1);

use App\Enums\Llm\FinishReason;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use Carbon\CarbonImmutable;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as PrismStructuredResponse;
use Prism\Prism\Text\Response as PrismTextResponse;
use Prism\Prism\ValueObjects\Meta as PrismMeta;
use Prism\Prism\ValueObjects\Usage as PrismUsage;

function vllmOptions(): LlmOptions
{
    return new LlmOptions(
        provider: 'local-vllm',
        model: 'Qwen3-4B-Instruct',
        temperature: 0.0,
        maxTokens: 2048,
        baseUrl: 'http://vllm.internal:8000/v1',
        apiKeyEnv: null,
    );
}

test('complete() уходит на OpenAI-совместимый endpoint и маппит usage/finishReason', function (): void {
    $fake = Prism::fake([
        new PrismTextResponse(
            steps: collect([]),
            text: 'привет',
            finishReason: PrismFinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new PrismUsage(promptTokens: 100, completionTokens: 20, cacheReadInputTokens: 30),
            meta: new PrismMeta('id-1', 'Qwen3-4B-Instruct'),
            messages: collect([]),
        ),
    ]);

    $provider = new OpenAiCompatibleProvider;
    $response = $provider->complete(new LlmPrompt(systemPart: 'система', userParts: ['вопрос']), vllmOptions());

    expect($response->data)->toBe('привет')
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(20)
        ->and($response->usage->cachedTokens)->toBe(30)
        ->and($response->usage->totalTokens)->toBe(150);

    $fake->assertCallCount(1);
});

test('completeStructured() шлёт RawSchema как есть (без Gemini-адаптации) и режим Structured', function (): void {
    $fake = Prism::fake([
        new PrismStructuredResponse(
            steps: collect([]),
            text: '{"foo":"bar"}',
            structured: ['foo' => 'bar'],
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(promptTokens: 10, completionTokens: 5),
            meta: new PrismMeta('id-2', 'Qwen3-4B-Instruct'),
        ),
    ]);

    $schema = new JsonSchema(['type' => 'object', '$defs' => ['X' => ['type' => 'object']], 'properties' => ['a' => ['$ref' => '#/$defs/X']]]);
    $provider = new OpenAiCompatibleProvider;

    $response = $provider->completeStructured(new LlmPrompt(systemPart: 's'), $schema, vllmOptions());

    expect($response->data)->toBe(['foo' => 'bar'])
        ->and($response->raw)->toBe('{"foo":"bar"}');

    $fake->assertRequest(function (array $recorded) use ($schema): void {
        // Без Gemini-специфичной down-конвертации (`$ref`/`$defs` разворачиваются НЕ здесь) —
        // OpenAI-совместимый JSON Schema draft понимает их нативно (в отличие от Gemini
        // `response_schema`, `GeminiResponseSchemaAdapter` тут не участвует).
        expect($recorded[0]->schema()->toArray())->toBe($schema->schema);
    });
});

test('complete() бросает исключение, если у маршрута не задан base_url', function (): void {
    $provider = new OpenAiCompatibleProvider;

    $options = new LlmOptions(provider: 'local-vllm', model: 'Qwen3-4B', temperature: 0.0, maxTokens: 100, baseUrl: null);

    expect(fn () => $provider->complete(new LlmPrompt(systemPart: 's'), $options))
        ->toThrow(RuntimeException::class, 'base_url');
});

test('complete() бросает исключение при непустых fileRefs — Files API не поддержан', function (): void {
    $provider = new OpenAiCompatibleProvider;

    $prompt = new LlmPrompt(
        systemPart: 's',
        fileRefs: [new LlmFileHandle(uri: 'files/x', expiresAt: CarbonImmutable::now()->addDay())],
    );

    expect(fn () => $provider->complete($prompt, vllmOptions()))
        ->toThrow(RuntimeException::class, 'Files API');
});
