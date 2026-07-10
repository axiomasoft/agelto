<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Procurement\Extraction\Dto\ChunkFetchResult;
use App\Services\Procurement\Extraction\Exceptions\MaxTokensExceededException;

/**
 * Источник чанков извлечения (D40, введён здесь вместе с `RowRangeChunkSource` — основной
 * путь, D3). Вторую реализацию (`WindowedChunkSource`, оконный путь §4.2) добавит P6.4 —
 * `ExtractionOrchestrator` не меняет свой цикл, только получает вторую реализацию за
 * `config('ai.extraction.windowed_enabled')`. Каждый метод — ОДИН вызов LLM (одна «единица
 * прогресса» для checkpoint-requeue, D31) + запись в `LlmCallRecorder` внутри реализации;
 * возвращаемый `ChunkFetchResult` несёт usage этого вызова для накопления в `ExtractionSession`.
 */
interface ChunkSource
{
    /**
     * Паспорт следующей неразобранной сметы (по порядку появления в документе); `data=null` в
     * ответе — смет в документе больше нет (терминатор цикла обнаружения, `docs/specs/v2/
     * 08-estimate-extraction.md` §4.1 п.3) — реализация сама решает, как сигнализировать
     * модели «уже найдено N смет, есть ли смета N+1».
     *
     * @param  list<string>  $knownEstimateNumbers  Номера/заголовки уже найденных смет (в
     *                                              порядке обнаружения) — контекст для запроса «следующая смета».
     */
    public function fetchPassport(
        int $analysisId,
        ResolvedRoute $route,
        ExtractionSession $session,
        int $ordinal,
        array $knownEstimateNumbers,
    ): ChunkFetchResult;

    /**
     * Диапазон строк `[$rowStart, $rowStart + $rowLength)` сметы `$estimateOrdinal`. Пустой
     * `rows` в ответе — сигнал «строк в этом диапазоне/смете больше нет» (терминатор цикла
     * построчного извлечения).
     *
     * @throws MaxTokensExceededException
     */
    public function fetchRows(
        int $analysisId,
        ResolvedRoute $route,
        ExtractionSession $session,
        int $estimateOrdinal,
        int $rowStart,
        int $rowLength,
    ): ChunkFetchResult;

    public function fetchTotals(
        int $analysisId,
        ResolvedRoute $route,
        ExtractionSession $session,
        int $estimateOrdinal,
    ): ChunkFetchResult;
}
