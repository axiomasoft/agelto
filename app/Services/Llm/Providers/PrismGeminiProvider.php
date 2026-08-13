<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Enums\Llm\FinishReason;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Contracts\OutboundProxy;
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
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
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
    // Литерал из `vendor/prism-php/prism/src/Providers/Gemini/Handlers/Structured.php:180-185` —
    // единственный признак, отличающий вендорское MAX_TOKENS-исключение от любого другого
    // `PrismException` (сетевая ошибка, невалидная схема и т.д.).
    private const string MAX_TOKENS_SIGNATURE = 'Gemini hit token limit with high thinking token usage.';

    public function __construct(private OutboundProxy $outboundProxy) {}

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
        try {
            $response = $this->withRateLimit(fn () => $this->structuredRequest($prompt, $schema, $options)->asStructured());
        } catch (PrismException $exception) {
            return $this->maxTokensResponseFrom($exception);
        }

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

    /**
     * Узкий перевод вендорского MAX_TOKENS-исключения (D98/D99, P2.9): на исходе «MAX_TOKENS +
     * thinking > 0 + пустой/невалидный JSON» Gemini-хендлер Prism БРОСАЕТ `PrismException` вместо
     * ответа с признаком обрезки (`vendor/prism-php/prism/src/Providers/Gemini/Handlers/Structured.php:165-186`),
     * из-за чего спроектированный предохранитель («MAX_TOKENS → делим окно диапазона пополам»,
     * `RowRangeChunkSource`/`ExtractionOrchestrator`) не срабатывает НИ РАЗУ — исключение летит
     * мимо `catch (MaxTokensExceededException)` и роняет документ целиком. Ловится ТОЛЬКО
     * исключение с вендорской сигнатурой; любое другое `PrismException` пробрасывается без
     * изменений — перевод узкий, а не широкий.
     */
    private function maxTokensResponseFrom(PrismException $exception): LlmStructuredResponse
    {
        if (! str_contains($exception->getMessage(), self::MAX_TOKENS_SIGNATURE)) {
            throw $exception;
        }

        return new LlmStructuredResponse(
            data: [],
            usage: $this->usageFromExceptionMessage($exception->getMessage()),
            finishReason: FinishReason::MaxTokens,
            raw: '',
        );
    }

    /**
     * Best effort: вендор кладёт разложение бюджета текстом
     * («{prompt} prompt + {thinking} thinking + {output} output = {total} total», где
     * `output = candidatesTokenCount - thoughtsTokenCount` — см. вендорский исходник); не
     * разобралось (формат исключения сменился) → нули, парсер диагностики не имеет права стать
     * новым источником отказа. `completionTokens` восстанавливает `candidatesTokenCount +
     * thoughtsTokenCount` (`output + thinking) + thinking`) — ТА ЖЕ семантика double-count
     * reasoning-токенов, что `usageFrom()` для обычного ответа (докблок метода: Google билluет
     * `thoughtsTokenCount` по ставке `candidatesTokenCount`, без сложения `cost_micros` занижает
     * реальный счёт); `cachedTokens` вендор в тексте исключения не несёт вовсе.
     */
    private function usageFromExceptionMessage(string $message): LlmUsage
    {
        if (preg_match('/Token usage: (-?\d+) prompt \+ (-?\d+) thinking \+ (-?\d+) output = (-?\d+) total\./', $message, $matches) !== 1) {
            return new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0);
        }

        $thinkingTokens = (int) $matches[2];
        $outputTokens = (int) $matches[3];

        return new LlmUsage(
            promptTokens: (int) $matches[1],
            completionTokens: $outputTokens + 2 * $thinkingTokens,
            cachedTokens: 0,
            totalTokens: (int) $matches[4],
        );
    }

    public function createCache(CacheableContent $content, LlmCacheOptions $options): LlmCacheHandle
    {
        $cached = $this->geminiProvider()->cache(
            model: $options->model,
            messages: $this->fileMessages($this->fileRefs($content->fileRefs)),
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
            ->withPrompt($this->userText($prompt->userParts), $this->fileMedia($this->fileRefs($prompt->fileRefs)))
            ->usingTemperature($options->temperature)
            ->withMaxTokens($options->maxTokens)
            ->withProviderOptions($this->providerOptions($options));

        return $request->withClientOptions($this->clientOptions());
    }

    private function structuredRequest(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): StructuredPendingRequest
    {
        $request = Prism::structured()
            ->using(PrismProvider::Gemini, $options->model)
            ->withSchema(new RawSchema(name: 'response', schema: (new GeminiResponseSchemaAdapter)->adapt($schema->schema)))
            ->withSystemPrompt($prompt->systemPart)
            ->withPrompt($this->userText($prompt->userParts), $this->fileMedia($this->fileRefs($prompt->fileRefs)))
            ->usingTemperature($options->temperature)
            ->withMaxTokens($options->maxTokens)
            ->withProviderOptions($this->providerOptions($options));

        return $request->withClientOptions($this->clientOptions());
    }

    /**
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        return [
            'timeout' => $this->intConfig('ai.gemini.request_timeout_seconds', 90),
            ...$this->outboundProxy->optionsFor($this->host()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerOptions(LlmOptions $options): array
    {
        return array_filter(
            [
                'cachedContentName' => $options->cacheHandle?->id,
                'thinkingLevel' => $options->thinkingLevel,
            ],
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
     * @param  array<mixed>  $value
     * @return list<LlmFileHandle>
     */
    private function fileRefs(array $value): array
    {
        $fileRefs = [];

        foreach ($value as $file) {
            if (! $file instanceof LlmFileHandle) {
                throw new \LogicException('File refs должны состоять из LlmFileHandle.');
            }

            $fileRefs[] = $file;
        }

        return $fileRefs;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function userText(array $value): string
    {
        $parts = [];

        foreach ($value as $part) {
            if (! is_string($part)) {
                throw new \LogicException('User prompt должен состоять из строковых частей.');
            }

            $parts[] = $part;
        }

        return implode("\n\n", $parts);
    }

    private function geminiProvider(): Gemini
    {
        $provider = Prism::provider(PrismProvider::Gemini);

        if (! $provider instanceof Gemini) {
            throw new \LogicException('Prism Gemini provider должен быть экземпляром Gemini.');
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $raw  Сырой ответ Gemini (`candidates`/`usageMetadata`)
     */
    private function usageFrom(array $raw): LlmUsage
    {
        $promptTokenCount = $this->integerValue(data_get($raw, 'usageMetadata.promptTokenCount', 0), 'usageMetadata.promptTokenCount');
        $cachedTokenCount = $this->integerValue(data_get($raw, 'usageMetadata.cachedContentTokenCount', 0), 'usageMetadata.cachedContentTokenCount');
        // `thoughtsTokenCount` (reasoning-модели, gemini-3.x) билluется провайдером по ТОЙ ЖЕ
        // ставке, что `candidatesTokenCount` (output) — Google не разводит их по цене, только по
        // назначению. Без сложения `cost_micros`/`ai:cost-report` молча занижают реальный счёт
        // (найдено P6.2 2026-07-28: расхождение трекинга с фактическим биллингом владельца).
        $thoughtsTokenCount = $this->integerValue(data_get($raw, 'usageMetadata.thoughtsTokenCount', 0), 'usageMetadata.thoughtsTokenCount');

        return new LlmUsage(
            promptTokens: max($promptTokenCount - $cachedTokenCount, 0),
            completionTokens: $this->integerValue(data_get($raw, 'usageMetadata.candidatesTokenCount', 0), 'usageMetadata.candidatesTokenCount') + $thoughtsTokenCount,
            cachedTokens: $cachedTokenCount,
            totalTokens: $this->integerValue(data_get($raw, 'usageMetadata.totalTokenCount', 0), 'usageMetadata.totalTokenCount'),
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
        $max = $this->intConfig('ai.gemini.rate_limit.max', 0);

        if ($max <= 0 || app()->runningUnitTests()) {
            return $callback();
        }

        return Redis::throttle('gemini:generate')
            ->allow($max)
            ->every($this->intConfig('ai.gemini.rate_limit.every_seconds', 60))
            ->block($this->intConfig('ai.gemini.rate_limit.block_seconds', 30))
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
            ->withOptions($this->outboundProxy->optionsFor($this->host()))
            ->post($this->filesUploadUrl(), [
                'file' => ['display_name' => basename($path)],
            ]);

        $response->throw();

        $uploadUrl = $response->header('x-goog-upload-url');

        if ($uploadUrl === '') {
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
            ->withOptions($this->outboundProxy->optionsFor($this->host()))
            ->withBody($content, $mimeType)
            ->post($uploadUrl);

        $response->throw();

        return $this->jsonObject($response->json('file', []), 'Gemini Files API upload response');
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function pollUntilActive(array $file): LlmFileHandle
    {
        $name = ltrim($this->stringValue($file['name'] ?? '', 'Gemini file name'), '/');
        $state = $this->stringValue($file['state'] ?? 'ACTIVE', 'Gemini file state');
        $deadline = CarbonImmutable::now()->addSeconds($this->intConfig('ai.gemini.files.poll_timeout_seconds', 60));
        $intervalMs = $this->intConfig('ai.gemini.files.poll_interval_ms', 500);

        while ($state === 'PROCESSING' && CarbonImmutable::now()->lessThan($deadline)) {
            usleep($intervalMs * 1000);

            $response = $this->geminiClient()->get("/{$name}");
            $response->throw();

            $file = $this->jsonObject($response->json(), 'Gemini Files API poll response');
            $state = $this->stringValue($file['state'] ?? 'ACTIVE', 'Gemini file state');
        }

        if ($state !== 'ACTIVE') {
            throw new \RuntimeException("Gemini Files API: файл не перешёл в ACTIVE за отведённое время (state={$state}).");
        }

        return new LlmFileHandle(
            uri: $this->stringValue($file['uri'] ?? null, 'Gemini file URI'),
            expiresAt: CarbonImmutable::parse($this->stringValue($file['expirationTime'] ?? null, 'Gemini file expirationTime')),
        );
    }

    private function filesUploadUrl(): string
    {
        return rtrim($this->stringConfig('ai.gemini.files.base_url'), '/').'/upload/v1beta/files';
    }

    /**
     * Клиент для `v1beta/{resource}` (cache CRUD, file polling) — НЕ путать с базовым URL
     * Prism (`config('prism.providers.gemini.url')`, уже несёт суффикс `/models` под generateContent).
     */
    private function geminiClient(): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => $this->apiKey()])
            ->baseUrl(rtrim($this->stringConfig('ai.gemini.files.base_url'), '/').'/v1beta')
            ->withOptions($this->outboundProxy->optionsFor($this->host()));
    }

    private function apiKey(): string
    {
        return $this->stringConfig('prism.providers.gemini.api_key');
    }

    /**
     * Канонический хост Gemini API (research/02 §9.1 — без схемы/порта), вход порта
     * `OutboundProxy::optionsFor()`. Prism (generateContent) и Files API/cache CRUD бьют в один
     * и тот же хост (`config('ai.gemini.files.base_url')` и `GEMINI_URL` из `config/prism.php`
     * оба указывают на `generativelanguage.googleapis.com`).
     */
    private function host(): string
    {
        $host = parse_url($this->stringConfig('ai.gemini.files.base_url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new \LogicException('ai.gemini.files.base_url должен содержать host.');
        }

        return $host;
    }

    private function intConfig(string $key, int $default): int
    {
        return $this->integerValue(config($key, $default), $key);
    }

    private function integerValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \LogicException("{$context} должен быть целым числом.");
    }

    private function stringConfig(string $key): string
    {
        return $this->stringValue(config($key), $key);
    }

    private function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value)) {
            throw new \LogicException("{$context} должен быть строкой.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $context): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \LogicException("{$context} должен быть JSON-объектом.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
