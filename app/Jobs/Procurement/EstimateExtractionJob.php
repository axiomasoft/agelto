<?php

declare(strict_types=1);

namespace App\Jobs\Procurement;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Заглушка полного извлечения смет (ТЗ 03 §3.5, `docs/specs/v2/08-estimate-extraction.md`):
 * `ScreeningOutcomeHandler` (P5.2, Scope Excluded) диспатчит её для `document_type=estimate`
 * за гейтом `config('ai.extraction.enabled', false)` (default выключен — боевая реализация,
 * checkpoint-requeue через `ChunkSource`/`RowRangeChunkSource`, `ShouldBeUniqueUntilProcessing`
 * (D31/D39/D40), появится в P6.1). Здесь — только факт диспатча со стороны скрининга.
 */
final class EstimateExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $documentId,
    ) {
        $this->onQueue('ai_extraction');
    }

    public function handle(): void
    {
        Log::info('EstimateExtractionJob: заглушка, боевая реализация — P6.1', ['document_id' => $this->documentId]);
    }
}
