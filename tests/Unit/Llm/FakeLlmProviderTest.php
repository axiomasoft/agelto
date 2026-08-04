<?php

declare(strict_types=1);

use App\Enums\Llm\FinishReason;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use Tests\Support\Doubles\FakeLlmProvider;

function fakeLlmOptions(): LlmOptions
{
    return new LlmOptions(provider: 'gemini', model: 'gemini-2.5-flash', temperature: 0.0, maxTokens: 1024);
}

test('succeeding() отдаёт заготовленный структурированный ответ и запоминает промт', function (): void {
    $usage = new LlmUsage(promptTokens: 100, completionTokens: 20, cachedTokens: 80, totalTokens: 120);
    $response = new LlmStructuredResponse(data: ['foo' => 'bar'], usage: $usage, finishReason: FinishReason::Stop, raw: '{"foo":"bar"}');
    $provider = FakeLlmProvider::succeeding($response);
    $prompt = new LlmPrompt(systemPart: 'система: правило A');

    $actual = $provider->completeStructured($prompt, new JsonSchema(['type' => 'object']), fakeLlmOptions());

    expect($actual)->toBe($response)
        ->and($provider->receivedPrompts())->toEqual([$prompt]);
});

test('truncated() отдаёт ответ с finishReason MaxTokens', function (): void {
    $provider = FakeLlmProvider::truncated();

    $actual = $provider->completeStructured(new LlmPrompt(systemPart: 's'), new JsonSchema([]), fakeLlmOptions());

    expect($actual->finishReason)->toBe(FinishReason::MaxTokens)
        ->and($actual->data)->toBe([]);
});

test('throwing() пробрасывает заготовленное исключение при вызове', function (): void {
    $provider = FakeLlmProvider::throwing(new RuntimeException('лимит провайдера'));

    expect(fn () => $provider->complete(new LlmPrompt(systemPart: 's'), fakeLlmOptions()))
        ->toThrow(RuntimeException::class, 'лимит провайдера');
});

test('andThen() докладывает следующий исход для сценариев re-ask', function (): void {
    $usage = new LlmUsage(promptTokens: 10, completionTokens: 5, cachedTokens: 0, totalTokens: 15);
    $first = new LlmStructuredResponse(data: [], usage: $usage, finishReason: FinishReason::MaxTokens, raw: '');
    $second = new LlmStructuredResponse(data: ['ok' => true], usage: $usage, finishReason: FinishReason::Stop, raw: '{"ok":true}');
    $provider = FakeLlmProvider::succeeding($first)->andThen($second);

    $firstCall = $provider->completeStructured(new LlmPrompt(systemPart: 's'), new JsonSchema([]), fakeLlmOptions());
    $secondCall = $provider->completeStructured(new LlmPrompt(systemPart: 's'), new JsonSchema([]), fakeLlmOptions());

    expect($firstCall)->toBe($first)
        ->and($secondCall)->toBe($second)
        ->and($provider->receivedPrompts())->toHaveCount(2);
});

test('complete() требует LlmResponse в очереди, не LlmStructuredResponse', function (): void {
    $usage = new LlmUsage(promptTokens: 1, completionTokens: 1, cachedTokens: 0, totalTokens: 2);
    $provider = FakeLlmProvider::succeeding(new LlmStructuredResponse(data: [], usage: $usage, finishReason: FinishReason::Stop, raw: ''));

    expect(fn () => $provider->complete(new LlmPrompt(systemPart: 's'), fakeLlmOptions()))
        ->toThrow(RuntimeException::class);
});

test('очередь пуста → исключение вместо тихого null', function (): void {
    $usage = new LlmUsage(promptTokens: 1, completionTokens: 1, cachedTokens: 0, totalTokens: 2);
    $provider = FakeLlmProvider::succeeding(new LlmResponse(data: 'ok', usage: $usage, finishReason: FinishReason::Stop, raw: 'ok'));
    $provider->complete(new LlmPrompt(systemPart: 's'), fakeLlmOptions());

    expect(fn () => $provider->complete(new LlmPrompt(systemPart: 's'), fakeLlmOptions()))
        ->toThrow(RuntimeException::class, 'очередь заготовленных исходов пуста');
});
