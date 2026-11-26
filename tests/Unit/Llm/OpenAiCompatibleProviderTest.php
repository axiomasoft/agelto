<?php

declare(strict_types=1);

use App\Exceptions\Llm\UnsupportedConsumer;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\CatalogOnlyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function vllmOptions(): LlmOptions
{
    return new LlmOptions(provider: 'local-vllm', model: 'Qwen3-4B-Instruct', temperature: 0.0,
        maxTokens: 2048, baseUrl: 'http://vllm.internal:8000/v1', apiKeyEnv: null);
}

function assertUnownedOpenAiDenied(Closure $call): void
{
    Http::fake();
    expect($call)->toThrow(UnsupportedConsumer::class);
    Http::assertNothingSent();
}

test('complete without a durable owner is Unsupported before SDK usage or finish-reason mapping', function (): void {
    assertUnownedOpenAiDenied(fn () => app(CatalogOnlyProvider::class)->complete(
        new LlmPrompt(systemPart: 'система', userParts: ['вопрос']), vllmOptions()));
});

test('structured caller without a durable owner cannot bypass the gate with a raw schema', function (): void {
    $schema = new JsonSchema(['type' => 'object', '$defs' => ['X' => ['type' => 'object']], 'properties' => ['a' => ['$ref' => '#/$defs/X']]]);
    assertUnownedOpenAiDenied(fn () => app(CatalogOnlyProvider::class)->completeStructured(new LlmPrompt(systemPart: 's'), $schema, vllmOptions()));
});

test('missing base URL does not authorize an unowned OpenAI caller', function (): void {
    $options = new LlmOptions(provider: 'local-vllm', model: 'Qwen3-4B', temperature: 0.0, maxTokens: 100, baseUrl: null);
    assertUnownedOpenAiDenied(fn () => app(CatalogOnlyProvider::class)->complete(new LlmPrompt(systemPart: 's'), $options));
});

test('file references cannot activate an unowned OpenAI Files API', function (): void {
    $prompt = new LlmPrompt(systemPart: 's', fileRefs: [new LlmFileHandle(uri: 'files/x', expiresAt: CarbonImmutable::now()->addDay())]);
    assertUnownedOpenAiDenied(fn () => app(CatalogOnlyProvider::class)->complete($prompt, vllmOptions()));
});

test('configured missing secret still denies the unowned OpenAI caller before reading credentials', function (): void {
    $options = new LlmOptions(provider: 'protected-vllm', model: 'Qwen3-4B-Instruct', temperature: 0.0,
        maxTokens: 2048, baseUrl: 'http://vllm.internal:8000/v1', apiKeyEnv: 'P313_UNOWNED_NO_SECRET');
    assertUnownedOpenAiDenied(fn () => app(CatalogOnlyProvider::class)->complete(new LlmPrompt(systemPart: 's'), $options));
});
