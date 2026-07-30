<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Dto;

use App\Dto\Procurement\Analyzers\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Services\Llm\Dto\LlmUsage;

/**
 * Обёртка вызова `ChunkSource`/`WindowedChunkSource` (P6.4, D42 — разные контракты, общая
 * обёртка результата): доменный ответ шага + usage этого конкретного LLM-вызова —
 * `ExtractionOrchestrator` накапливает usage в `ExtractionSession` (Intent P6.1 «счётчики
 * usage»), не читая его из побочных источников. `data=null` — только у `fetchPassport()`
 * (терминатор цикла обнаружения смет, см. `ChunkSource::fetchPassport()`); `DiscoveryResponse` —
 * `WindowedChunkSource::fetchDiscoveryWindow()`, никогда `null` (пустой `markers=[]` валиден).
 */
final readonly class ChunkFetchResult
{
    public function __construct(
        public PassportResponse|RowsChunkResponse|TotalsResponse|DiscoveryResponse|null $data,
        public LlmUsage $usage,
    ) {}
}
