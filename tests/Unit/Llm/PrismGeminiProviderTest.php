<?php

declare(strict_types=1);

use App\Enums\Procurement\FinishReason;
use App\Services\Llm\Dto\CacheableContent;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmCacheHandle;
use App\Services\Llm\Dto\LlmCacheOptions;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as PrismStructuredResponse;
use Prism\Prism\Text\Response as PrismTextResponse;
use Prism\Prism\ValueObjects\Meta as PrismMeta;
use Prism\Prism\ValueObjects\Usage as PrismUsage;

function geminiOptions(?LlmCacheHandle $cacheHandle = null): LlmOptions
{
    return new LlmOptions(
        provider: 'gemini',
        model: 'gemini-2.5-flash',
        temperature: 0.0,
        maxTokens: 2048,
        cacheHandle: $cacheHandle,
    );
}

test('completeStructured() маппит usage из сырого usageMetadata, а не из Prism-usage хендлера', function (): void {
    // Prism v0.100.1: Structured-хендлер НЕ вычитает cachedContentTokenCount из promptTokenCount
    // (в отличие от Text-хендлера) — Prism::usage намеренно "неверный" ниже, чтобы доказать,
    // что адаптер читает raw.usageMetadata сам, а не доверяет $response->usage.
    $raw = [
        'usageMetadata' => [
            'promptTokenCount' => 120,
            'cachedContentTokenCount' => 80,
            'candidatesTokenCount' => 20,
            'totalTokenCount' => 140,
        ],
    ];

    $fake = Prism::fake([
        new PrismStructuredResponse(
            steps: collect([]),
            text: '{"foo":"bar"}',
            structured: ['foo' => 'bar'],
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(promptTokens: 999_999, completionTokens: 999_999),
            meta: new PrismMeta('id-1', 'gemini-2.5-flash'),
            raw: $raw,
        ),
    ]);

    $provider = new PrismGeminiProvider;
    $prompt = new LlmPrompt(systemPart: 'система: правило A', userParts: ['извлеки строки 1-40']);

    $response = $provider->completeStructured($prompt, new JsonSchema(['type' => 'object']), geminiOptions());

    expect($response->data)->toBe(['foo' => 'bar'])
        ->and($response->finishReason)->toBe(FinishReason::Stop)
        ->and($response->usage->promptTokens)->toBe(40)
        ->and($response->usage->cachedTokens)->toBe(80)
        ->and($response->usage->completionTokens)->toBe(20)
        ->and($response->usage->totalTokens)->toBe(140);

    $fake->assertCallCount(1);
});

test('completeStructured() кладёт в raw модельный JSON-текст, а не envelope Gemini-ответа', function (): void {
    // Контрактный шов с StructuredResponseValidator (P3.4): raw обязан быть тем же JSON-текстом,
    // что и Prism Structured\Response::$text (сам ответ модели), НЕ $response->raw
    // (candidates/usageMetadata/modelVersion) — иначе json_validate()/validateAndCreate() по raw
    // всегда падает на envelope (P3 phase-review, HIGH).
    Prism::fake([
        new PrismStructuredResponse(
            steps: collect([]),
            text: '{"foo":"bar"}',
            structured: ['foo' => 'bar'],
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(promptTokens: 1, completionTokens: 1),
            meta: new PrismMeta('id-5', 'gemini-2.5-flash'),
            raw: ['candidates' => [], 'usageMetadata' => [], 'modelVersion' => 'gemini-2.5-flash'],
        ),
    ]);

    $provider = new PrismGeminiProvider;
    $response = $provider->completeStructured(
        new LlmPrompt(systemPart: 's'),
        new JsonSchema(['type' => 'object']),
        geminiOptions(),
    );

    expect($response->raw)->toBe('{"foo":"bar"}');
});

test('completeStructured() передаёт canonical JSON Schema как RawSchema без перестройки в DSL', function (): void {
    $fake = Prism::fake([
        new PrismStructuredResponse(
            steps: collect([]),
            text: '{}',
            structured: [],
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(promptTokens: 0, completionTokens: 0),
            meta: new PrismMeta('id-2', 'gemini-2.5-flash'),
            raw: ['usageMetadata' => []],
        ),
    ]);

    $schema = new JsonSchema(['type' => 'object', 'properties' => ['rowIndex' => ['type' => 'integer']]]);
    $provider = new PrismGeminiProvider;

    $provider->completeStructured(new LlmPrompt(systemPart: 's'), $schema, geminiOptions());

    $fake->assertRequest(function (array $recorded) use ($schema): void {
        expect($recorded[0]->schema()->toArray())->toBe($schema->schema);
    });
});

test('complete() маппит MAX_TOKENS (Prism Length) в FinishReason::MaxTokens', function (): void {
    Prism::fake([
        new PrismTextResponse(
            steps: collect([]),
            text: '{"incomplete":',
            finishReason: PrismFinishReason::Length,
            toolCalls: [],
            toolResults: [],
            usage: new PrismUsage(promptTokens: 0, completionTokens: 0),
            meta: new PrismMeta('id-3', 'gemini-2.5-flash'),
            messages: collect([]),
            raw: ['usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15]],
        ),
    ]);

    $provider = new PrismGeminiProvider;
    $response = $provider->complete(new LlmPrompt(systemPart: 's'), geminiOptions());

    expect($response->finishReason)->toBe(FinishReason::MaxTokens);
});

test('cacheHandle из LlmOptions уходит в providerOptions cachedContentName', function (): void {
    $fake = Prism::fake([
        new PrismStructuredResponse(
            steps: collect([]),
            text: '{}',
            structured: [],
            finishReason: PrismFinishReason::Stop,
            usage: new PrismUsage(promptTokens: 0, completionTokens: 0),
            meta: new PrismMeta('id-4', 'gemini-2.5-flash'),
            raw: ['usageMetadata' => []],
        ),
    ]);

    $handle = new LlmCacheHandle(id: 'cachedContents/abc123', expiresAt: CarbonImmutable::now()->addHour());
    $provider = new PrismGeminiProvider;

    $provider->completeStructured(new LlmPrompt(systemPart: 's'), new JsonSchema([]), geminiOptions($handle));

    $fake->assertRequest(function (array $recorded): void {
        expect($recorded[0]->providerOptions('cachedContentName'))->toBe('cachedContents/abc123');
    });
});

test('createCache() создаёт explicit context cache через Gemini API и возвращает LlmCacheHandle', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/cachedContents' => Http::response([
            'name' => 'cachedContents/abc123',
            'expireTime' => now()->addHour()->toIso8601ZuluString(),
            'usageMetadata' => ['totalTokenCount' => 5000],
        ]),
    ]);

    $provider = new PrismGeminiProvider;
    $handle = $provider->createCache(
        new CacheableContent(systemPart: 'стабильная системная часть'),
        new LlmCacheOptions(model: 'gemini-2.5-flash', ttl: CarbonInterval::hour()),
    );

    expect($handle->id)->toBe('cachedContents/abc123');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), '/cachedContents'));
});

test('refreshCache() PATCH-ит expireTime по id кэша (Prism кэш не покрывает — тонкий HTTP-клиент)', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/cachedContents/abc123*' => Http::response(['name' => 'cachedContents/abc123']),
    ]);

    $provider = new PrismGeminiProvider;
    $provider->refreshCache(new LlmCacheHandle(id: 'cachedContents/abc123', expiresAt: CarbonImmutable::now()), CarbonInterval::hour());

    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH' && str_contains($request->url(), 'updateMask=expireTime'));
});

test('deleteCache() DELETE-ит кэш по id (Prism кэш не покрывает — тонкий HTTP-клиент)', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/v1beta/cachedContents/abc123' => Http::response([]),
    ]);

    $provider = new PrismGeminiProvider;
    $provider->deleteCache(new LlmCacheHandle(id: 'cachedContents/abc123', expiresAt: CarbonImmutable::now()));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
});

test('uploadFile() проходит resumable-протокол Files API и возвращает handle при мгновенном ACTIVE', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'gemini-upload-test-');
    file_put_contents($path, '%PDF-1.4 fake content');

    Http::fake([
        'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response(
            body: [],
            status: 200,
            headers: ['x-goog-upload-url' => 'https://gemini-upload.test/session-1'],
        ),
        'gemini-upload.test/session-1' => Http::response([
            'file' => [
                'name' => 'files/abc123',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
                'state' => 'ACTIVE',
                'expirationTime' => now()->addHours(48)->toIso8601ZuluString(),
            ],
        ]),
    ]);

    $provider = new PrismGeminiProvider;
    $handle = $provider->uploadFile($path, 'application/pdf');

    expect($handle)->toBeInstanceOf(LlmFileHandle::class)
        ->and($handle->uri)->toBe('https://generativelanguage.googleapis.com/v1beta/files/abc123');

    unlink($path);
});

test('uploadFile() опрашивает состояние, пока PROCESSING, и возвращает handle по ACTIVE', function (): void {
    config(['ai.gemini.files.poll_interval_ms' => 1]);

    $path = tempnam(sys_get_temp_dir(), 'gemini-upload-test-');
    file_put_contents($path, '%PDF-1.4 fake content');

    Http::fake([
        'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response(
            body: [],
            status: 200,
            headers: ['x-goog-upload-url' => 'https://gemini-upload.test/session-2'],
        ),
        'gemini-upload.test/session-2' => Http::response([
            'file' => ['name' => 'files/def456', 'state' => 'PROCESSING'],
        ]),
        'generativelanguage.googleapis.com/v1beta/files/def456' => Http::response([
            'name' => 'files/def456',
            'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/def456',
            'state' => 'ACTIVE',
            'expirationTime' => now()->addHours(48)->toIso8601ZuluString(),
        ]),
    ]);

    $provider = new PrismGeminiProvider;
    $handle = $provider->uploadFile($path, 'application/pdf');

    expect($handle->uri)->toBe('https://generativelanguage.googleapis.com/v1beta/files/def456');

    unlink($path);
});
