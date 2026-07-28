<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Enums\Procurement\FinishReason;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Contracts\SupportsContextCaching;
use App\Services\Llm\Contracts\SupportsFileUpload;
use App\Services\Llm\Dto\CacheableContent;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmCacheHandle;
use App\Services\Llm\Dto\LlmCacheOptions;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Schema\GeminiResponseSchemaAdapter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage as PrismSystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage as PrismUserMessage;

/**
 * Транспорт Gemini через Prism (D8, D14; ТЗ 04 §2.1). Prism покрывает text/structured
 * generation + СОЗДАНИЕ explicit context cache; НЕ покрывает Files API (upload/poll) и
 * refresh/delete кэша (research/G §1, подтверждено чтением `vendor/prism-php/prism`:
 * `Providers\Gemini\Gemini::cache()` — только создание, `DocumentMapper`/`MessageMap` не
 * содержат upload) — эти два участка реализованы тонким HTTP-клиентом поверх
 * `Illuminate\Support\Facades\Http` внутри адаптера, контракт `LlmProvider` (P3.1) не меняется.
 *
 * Structured output: канонический `responseJsonSchema` от `JsonSchemaGenerator` (P2.3)
 * передаётся через `Prism\Schema\RawSchema` БЕЗ перестройки в `ObjectSchema`/`StringSchema`-DSL
 * (Known Deviations P3.2) — `RawSchema` существует именно для сырых JSON Schema и провайдер-
 * агностична; `SchemaMap` Gemini-адаптера Prism пропускает её как есть (кроме `additionalProperties`,
 * который Gemini `responseSchema` не поддерживает и так).
 *
 * Usage мапится из СЫРОГО `usageMetadata` ответа Gemini (`$response->raw`), а не из
 * `$response->usage` самого Prism: в Prism v0.100.1 `Text`-хендлер вычитает
 * `cachedContentTokenCount` из `promptTokenCount` при активном `cachedContentName`, а
 * `Structured`-хендлер — нет (расхождение между двумя хендлерами одной версии пакета).
 * Чтение из `usageMetadata` напрямую и ручное вычитание кэш-токенов даёт одинаковую
 * семантику `LlmUsage` (`promptTokens` НЕ включает `cachedTokens`) для обоих путей.
 */
final readonly class PrismGeminiProvider implements LlmProvider, SupportsContextCaching, SupportsFileUpload
{
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse
    {
        $response = $this->withRateLimit(fn () => $this->textRequest($prompt, $options)->asText());

        return new LlmResponse(
            data: $response->text,
            usage: $this->usageFrom($response->raw ?? []),
            finishReason: $this->mapFinishReason($response->finishReason),
            raw: json_encode($response->raw, JSON_THROW_ON_ERROR),
        );
    }

    public function completeStructured(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): LlmStructuredResponse
    {
        $response = $this->withRateLimit(fn () => $this->structuredRequest($prompt, $schema, $options)->asStructured());

        return new LlmStructuredResponse(
            data: $response->structured ?? [],
            usage: $this->usageFrom($response->raw ?? []),
            finishReason: $this->mapFinishReason($response->finishReason),
            // ВАЖНО: `LlmStructuredResponse.raw` — сырой МОДЕЛЬНЫЙ JSON-текст (`$response->text`,
            // Prism `Structured\Handlers\Structured::extractTextContent()` — только части ответа
            // без usageMetadata/candidates), НЕ `$response->raw` (весь envelope Gemini). Контракт
            // шва: `StructuredResponseValidator::validate()` (P3.4) делает json_validate()/
            // json_decode()/`$dataClass::validateAndCreate()` именно по этому полю, а
            // `ReaskPolicy::withReaskMessage()` вставляет его в промпт как «предыдущий ответ» —
            // envelope с `candidates`/`usageMetadata` сломал бы оба потребителя (P3 phase-review).
            raw: $response->text,
        );
    }

    public function createCache(CacheableContent $content, LlmCacheOptions $options): LlmCacheHandle
    {
        $cached = Prism::provider(PrismProvider::Gemini)->cache(
            model: $options->model,
            messages: $this->fileMessages($content->fileRefs),
            systemPrompts: [new PrismSystemMessage($content->systemPart)],
            ttl: (int) $options->ttl->totalSeconds,
        );

        return new LlmCacheHandle(id: $cached->name, expiresAt: CarbonImmutable::instance($cached->expiresAt));
    }

    /**
     * Prism не реализует update TTL кэша — PATCH `cachedContents/{id}?updateMask=expireTime`
     * дописан напрямую (research/G §1, "не покрыто из коробки").
     */
    public function refreshCache(LlmCacheHandle $handle, CarbonInterval $ttl): void
    {
        $this->geminiClient()
            ->patch("/{$handle->id}?updateMask=expireTime", [
                'expireTime' => CarbonImmutable::now()->add($ttl)->toIso8601ZuluString(),
            ])
            ->throw();
    }

    /**
     * Prism не реализует delete кэша — DELETE `cachedContents/{id}` дописан напрямую.
     */
    public function deleteCache(LlmCacheHandle $handle): void
    {
        $this->geminiClient()->delete("/{$handle->id}")->throw();
    }

    /**
     * Files API целиком отсутствует в Prism — резюмируемый (resumable) протокол загрузки
     * (research/E §3: 48ч TTL, до 2 GB) реализован тремя HTTP-раундтрипами: start → upload
     * + finalize → polling до `state=ACTIVE` с таймаутом (Implementation Rules P3.2).
     * `$mimeType` — реальный MIME вызывающего кода (v1-баг: захардкоженный `application/pdf`,
     * research/C §5), эта сигнатура его не угадывает.
     */
    public function uploadFile(string $path, string $mimeType): LlmFileHandle
    {
        $size = filesize($path);

        if ($size === false) {
            throw new \RuntimeException("Gemini Files API: файл недоступен для чтения: {$path}");
        }

        $uploadUrl = $this->startResumableUpload(path: $path, mimeType: $mimeType, size: $size);
        $file = $this->finishResumableUpload(uploadUrl: $uploadUrl, path: $path, mimeType: $mimeType, size: $size);

        return $this->pollUntilActive($file);
    }

    private function textRequest(LlmPrompt $prompt, LlmOptions $options): TextPendingRequest
    {
        $request = Prism::text()
            ->using(PrismProvider::Gemini, $options->model)
            ->withSystemPrompt($prompt->systemPart)
            ->withPrompt(implode("\n\n", $prompt->userParts), $this->fileMedia($prompt->fileRefs))
            ->usingTemperature($options->temperature)
            ->withMaxTokens($options->maxTokens)
            ->withProviderOptions($this->providerOptions($options));

        return $this->withProxy($request);
    }

    private function structuredRequest(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): StructuredPendingRequest
    {
        $request = Prism::structured()
            ->using(PrismProvider::Gemini, $options->model)
            ->withSchema(new RawSchema(name: 'response', schema: (new GeminiResponseSchemaAdapter)->adapt($schema->schema)))
            ->withSystemPrompt($prompt->systemPart)
            ->withPrompt(implode("\n\n", $prompt->userParts), $this->fileMedia($prompt->fileRefs))
            ->usingTemperature($options->temperature)
            ->withMaxTokens($options->maxTokens)
            ->withProviderOptions($this->providerOptions($options));

        return $this->withProxy($request);
    }

    /**
     * @template TRequest of TextPendingRequest|StructuredPendingRequest
     *
     * @param  TRequest  $request
     * @return TRequest
     */
    private function withProxy(TextPendingRequest|StructuredPendingRequest $request): TextPendingRequest|StructuredPendingRequest
    {
        $proxy = $this->proxy();

        $clientOptions = ['timeout' => (int) config('ai.gemini.request_timeout_seconds', 90)];

        if ($proxy !== null) {
            $clientOptions['proxy'] = $proxy;
        }

        return $request->withClientOptions($clientOptions);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerOptions(LlmOptions $options): array
    {
        return array_filter(
            ['cachedContentName' => $options->cacheHandle?->id],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param  list<LlmFileHandle>  $fileRefs
     * @return list<Document>
     */
    private function fileMedia(array $fileRefs): array
    {
        return array_map(
            static fn (LlmFileHandle $file): Document => Document::fromUrl($file->uri),
            $fileRefs,
        );
    }

    /**
     * @param  list<LlmFileHandle>  $fileRefs
     * @return list<PrismUserMessage>
     */
    private function fileMessages(array $fileRefs): array
    {
        if ($fileRefs === []) {
            return [];
        }

        return [new PrismUserMessage(content: '', additionalContent: $this->fileMedia($fileRefs))];
    }

    /**
     * @param  array<string, mixed>  $raw  Сырой ответ Gemini (`candidates`/`usageMetadata`)
     */
    private function usageFrom(array $raw): LlmUsage
    {
        $promptTokenCount = (int) data_get($raw, 'usageMetadata.promptTokenCount', 0);
        $cachedTokenCount = (int) data_get($raw, 'usageMetadata.cachedContentTokenCount', 0);
        // `thoughtsTokenCount` (reasoning-модели, gemini-3.x) билluется провайдером по ТОЙ ЖЕ
        // ставке, что `candidatesTokenCount` (output) — Google не разводит их по цене, только по
        // назначению. Без сложения `cost_micros`/`ai:cost-report` молча занижают реальный счёт
        // (найдено P6.2 2026-07-28: расхождение трекинга с фактическим биллингом владельца).
        $thoughtsTokenCount = (int) data_get($raw, 'usageMetadata.thoughtsTokenCount', 0);

        return new LlmUsage(
            promptTokens: max($promptTokenCount - $cachedTokenCount, 0),
            completionTokens: (int) data_get($raw, 'usageMetadata.candidatesTokenCount', 0) + $thoughtsTokenCount,
            cachedTokens: $cachedTokenCount,
            totalTokens: (int) data_get($raw, 'usageMetadata.totalTokenCount', 0),
        );
    }

    private function mapFinishReason(PrismFinishReason $reason): FinishReason
    {
        return match ($reason) {
            PrismFinishReason::Stop, PrismFinishReason::ToolCalls => FinishReason::Stop,
            PrismFinishReason::Length => FinishReason::MaxTokens,
            PrismFinishReason::ContentFilter => FinishReason::Safety,
            PrismFinishReason::Error, PrismFinishReason::Other, PrismFinishReason::Unknown => FinishReason::Other,
        };
    }

    /**
     * Троттлинг вызовов Redis (v1-канон `Old/app/Services/GeminiService.php::withRateLimit()`,
     * ключ `gemini:generate`) — `max<=0` (не откалибровано, default) ИЛИ юнит-тесты — без троттла.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withRateLimit(callable $callback): mixed
    {
        $max = (int) config('ai.gemini.rate_limit.max', 0);

        if ($max <= 0 || app()->runningUnitTests()) {
            return $callback();
        }

        return Redis::throttle('gemini:generate')
            ->allow($max)
            ->every((int) config('ai.gemini.rate_limit.every_seconds', 60))
            ->block((int) config('ai.gemini.rate_limit.block_seconds', 30))
            ->then(
                $callback,
                static function (): never {
                    throw new \RuntimeException('Gemini rate limit: слот не получен за отведённое время.');
                },
            );
    }

    private function startResumableUpload(string $path, string $mimeType, int $size): string
    {
        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey(),
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Length' => (string) $size,
            'X-Goog-Upload-Header-Content-Type' => $mimeType,
        ])
            ->when($this->proxy() !== null, fn (PendingRequest $client) => $client->withOptions(['proxy' => $this->proxy()]))
            ->post($this->filesUploadUrl(), [
                'file' => ['display_name' => basename($path)],
            ]);

        $response->throw();

        $uploadUrl = $response->header('x-goog-upload-url');

        if ($uploadUrl === '' || $uploadUrl === null) {
            throw new \RuntimeException('Gemini Files API: провайдер не вернул X-Goog-Upload-URL.');
        }

        return $uploadUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function finishResumableUpload(string $uploadUrl, string $path, string $mimeType, int $size): array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("Gemini Files API: не удалось прочитать файл: {$path}");
        }

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey(),
            'Content-Length' => (string) $size,
            'X-Goog-Upload-Offset' => '0',
            'X-Goog-Upload-Command' => 'upload, finalize',
        ])
            ->when($this->proxy() !== null, fn (PendingRequest $client) => $client->withOptions(['proxy' => $this->proxy()]))
            ->withBody($content, $mimeType)
            ->post($uploadUrl);

        $response->throw();

        return (array) $response->json('file', []);
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function pollUntilActive(array $file): LlmFileHandle
    {
        $name = ltrim((string) ($file['name'] ?? ''), '/');
        $state = (string) ($file['state'] ?? 'ACTIVE');
        $deadline = CarbonImmutable::now()->addSeconds((int) config('ai.gemini.files.poll_timeout_seconds', 60));
        $intervalMs = (int) config('ai.gemini.files.poll_interval_ms', 500);

        while ($state === 'PROCESSING' && CarbonImmutable::now()->lessThan($deadline)) {
            usleep($intervalMs * 1000);

            $response = $this->geminiClient()->get("/{$name}");
            $response->throw();

            $file = (array) $response->json();
            $state = (string) ($file['state'] ?? 'ACTIVE');
        }

        if ($state !== 'ACTIVE') {
            throw new \RuntimeException("Gemini Files API: файл не перешёл в ACTIVE за отведённое время (state={$state}).");
        }

        return new LlmFileHandle(
            uri: (string) $file['uri'],
            expiresAt: CarbonImmutable::parse((string) $file['expirationTime']),
        );
    }

    private function filesUploadUrl(): string
    {
        return rtrim((string) config('ai.gemini.files.base_url'), '/').'/upload/v1beta/files';
    }

    /**
     * Клиент для `v1beta/{resource}` (cache CRUD, file polling) — НЕ путать с базовым URL
     * Prism (`config('prism.providers.gemini.url')`, уже несёт суффикс `/models` под generateContent).
     */
    private function geminiClient(): PendingRequest
    {
        $client = Http::withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->baseUrl(rtrim((string) config('ai.gemini.files.base_url'), '/').'/v1beta');

        return $this->proxy() !== null ? $client->withOptions(['proxy' => $this->proxy()]) : $client;
    }

    private function apiKey(): string
    {
        return (string) config('prism.providers.gemini.api_key');
    }

    private function proxy(): ?string
    {
        $proxy = config('ai.gemini.proxy');

        return is_string($proxy) && $proxy !== '' ? $proxy : null;
    }
}
