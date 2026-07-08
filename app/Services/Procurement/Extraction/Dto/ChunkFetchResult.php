<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Dto;

use App\Dto\Procurement\Ai\Estimate\PassportResponse;
use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Ai\Estimate\TotalsResponse;
use App\Services\Llm\Dto\LlmUsage;

/**
 * Обёртка `ChunkSource`-вызова: доменный ответ шага + usage этого конкретного LLM-вызова —
 * `ExtractionOrchestrator` накапливает usage в `ExtractionSession` (Intent P6.1 «счётчики
 * usage»), не читая его из побочных источников. `data=null` — только у `fetchPassport()`
 * (терминатор цикла обнаружения смет, см. `ChunkSource::fetchPassport()`).
 */
final readonly class ChunkFetchResult
{
    public function __construct(
        public PassportResponse|RowsChunkResponse|TotalsResponse|null $data,
        public LlmUsage $usage,
    ) {}
}
