<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Services\Llm\Dto\LlmCacheHandle;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmUsage;

/**
 * Состояние ОДНОГО прогона `ExtractionOrchestrator` (Intent P6.1, `docs/specs/v2/
 * 08-estimate-extraction.md` §4д): загруженный файл (Files API, переиспользуется между
 * self-requeue-запусками через `analysis.progress['file_uri']`/`['file_expires_at']`, пока не
 * истёк TTL — избегаем повторной заливки всего документа на каждый чекпоинт), опциональный
 * explicit context cache (D8/ТЗ 04 §8), накопленный usage текущего запуска (суммарный usage
 * анализа — `analysis.progress['usage']`) и снимок прогресса (`analysis.progress`, jsonb).
 * Immutable (канон `final readonly`) — `with*()` возвращают новый снимок, оркестратор
 * перепривязывает переменную на каждой итерации цикла.
 *
 * @param  array<string, mixed>  $progress
 */
final readonly class ExtractionSession
{
    public function __construct(
        public LlmFileHandle $fileHandle,
        public ?LlmCacheHandle $cacheHandle,
        public LlmUsage $usage,
        public array $progress,
    ) {}

    public function withUsage(LlmUsage $additional): self
    {
        return new self(
            fileHandle: $this->fileHandle,
            cacheHandle: $this->cacheHandle,
            usage: new LlmUsage(
                promptTokens: $this->usage->promptTokens + $additional->promptTokens,
                completionTokens: $this->usage->completionTokens + $additional->completionTokens,
                cachedTokens: $this->usage->cachedTokens + $additional->cachedTokens,
                totalTokens: $this->usage->totalTokens + $additional->totalTokens,
            ),
            progress: $this->progress,
        );
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function withProgress(array $progress): self
    {
        return new self(
            fileHandle: $this->fileHandle,
            cacheHandle: $this->cacheHandle,
            usage: $this->usage,
            progress: $progress,
        );
    }
}
