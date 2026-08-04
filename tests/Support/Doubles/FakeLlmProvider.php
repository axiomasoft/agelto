<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Enums\Llm\FinishReason;
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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RuntimeException;
use Throwable;

/**
 * Тестовый двойник `LlmProvider` (P3.1, ТЗ 04 §2): очередь заготовленных исходов
 * (успех/обрезание/исключение) + запись полученных промтов для ассертов. Состояния выдаются
 * через фабричные методы `succeeding()`/`truncated()`/`throwing()` (канон тестов проекта —
 * без boolean-флагов); `andThen()` докладывает следующий исход для сценариев re-ask (P3.4).
 * Реализует `SupportsFileUpload` (P5.1 — orchestrator-тесты грузят файл перед классификацией)
 * заглушкой без реального I/O — `uploadFile()` только фиксирует запрошенные пути/MIME.
 * Реализует `SupportsContextCaching` (P7.1 — `LlmCacheManagerTest`) той же заглушечной схемой:
 * `cacheFailing()` переключает `createCache()`/`refreshCache()`/`deleteCache()` в режим исключения
 * для сценариев «ошибка провайдера → путь без кэша» (Implementation Rules P7.1).
 */
final class FakeLlmProvider implements LlmProvider, SupportsContextCaching, SupportsFileUpload
{
    /** @var list<LlmPrompt> */
    private array $receivedPrompts = [];

    /** @var list<array{path: string, mimeType: string}> */
    private array $uploadedFiles = [];

    /** @var list<CacheableContent> */
    private array $createdCaches = [];

    /** @var list<string> */
    private array $deletedCacheIds = [];

    private bool $cacheFailing = false;

    /**
     * @param  list<LlmResponse|LlmStructuredResponse|Throwable>  $queue
     */
    private function __construct(private array $queue) {}

    public static function succeeding(LlmResponse|LlmStructuredResponse $response): self
    {
        return new self([$response]);
    }

    public static function truncated(LlmUsage $usage = new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0)): self
    {
        return new self([
            new LlmStructuredResponse(data: [], usage: $usage, finishReason: FinishReason::MaxTokens, raw: ''),
        ]);
    }

    public static function throwing(Throwable $exception): self
    {
        return new self([$exception]);
    }

    public function andThen(LlmResponse|LlmStructuredResponse|Throwable $outcome): self
    {
        $this->queue[] = $outcome;

        return $this;
    }

    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse
    {
        $this->receivedPrompts[] = $prompt;

        $outcome = $this->dequeue();

        if (! $outcome instanceof LlmResponse) {
            throw new RuntimeException('FakeLlmProvider: следующий исход в очереди не LlmResponse (вызван complete()).');
        }

        return $outcome;
    }

    public function completeStructured(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): LlmStructuredResponse
    {
        $this->receivedPrompts[] = $prompt;

        $outcome = $this->dequeue();

        if (! $outcome instanceof LlmStructuredResponse) {
            throw new RuntimeException('FakeLlmProvider: следующий исход в очереди не LlmStructuredResponse (вызван completeStructured()).');
        }

        return $outcome;
    }

    public function uploadFile(string $path, string $mimeType): LlmFileHandle
    {
        $this->uploadedFiles[] = ['path' => $path, 'mimeType' => $mimeType];

        return new LlmFileHandle(uri: 'files/fake-'.md5($path), expiresAt: CarbonImmutable::now()->addHours(48));
    }

    public function createCache(CacheableContent $content, LlmCacheOptions $options): LlmCacheHandle
    {
        if ($this->cacheFailing) {
            throw new RuntimeException('FakeLlmProvider: createCache настроен на исключение.');
        }

        $this->createdCaches[] = $content;

        return new LlmCacheHandle(
            id: 'cachedContents/fake-'.md5($content->systemPart),
            expiresAt: CarbonImmutable::now()->add($options->ttl),
        );
    }

    public function refreshCache(LlmCacheHandle $handle, CarbonInterval $ttl): void
    {
        if ($this->cacheFailing) {
            throw new RuntimeException('FakeLlmProvider: refreshCache настроен на исключение.');
        }
    }

    public function deleteCache(LlmCacheHandle $handle): void
    {
        if ($this->cacheFailing) {
            throw new RuntimeException('FakeLlmProvider: deleteCache настроен на исключение.');
        }

        $this->deletedCacheIds[] = $handle->id;
    }

    /**
     * Переключает `createCache()`/`refreshCache()`/`deleteCache()` в режим исключения
     * (сценарий «ошибка провайдера → путь без кэша», Implementation Rules P7.1).
     */
    public function cacheFailing(): self
    {
        $this->cacheFailing = true;

        return $this;
    }

    /**
     * @return list<CacheableContent>
     */
    public function createdCaches(): array
    {
        return $this->createdCaches;
    }

    /**
     * @return list<string>
     */
    public function deletedCacheIds(): array
    {
        return $this->deletedCacheIds;
    }

    /**
     * @return list<LlmPrompt>
     */
    public function receivedPrompts(): array
    {
        return $this->receivedPrompts;
    }

    /**
     * @return list<array{path: string, mimeType: string}>
     */
    public function uploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    private function dequeue(): LlmResponse|LlmStructuredResponse
    {
        if ($this->queue === []) {
            throw new RuntimeException('FakeLlmProvider: очередь заготовленных исходов пуста.');
        }

        $outcome = array_shift($this->queue);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
