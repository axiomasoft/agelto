<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Dto\Procurement\Analyzers\Estimate\DiscoveryMarkerLine;
use App\Dto\Procurement\Analyzers\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\ExtractionRoute;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Llm\Contracts\SupportsFileUpload;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Procurement\Ai\UsageSnapshotRecorder;
use App\Services\Procurement\Extraction\Exceptions\MaxTokensExceededException;
use App\Services\Procurement\Extraction\ExtractionPreflight;
use App\Services\Procurement\Extraction\ExtractionSession;
use App\Services\Procurement\Prepare\RepresentationResolver;
use App\Services\Procurement\Screening\PdfPageSlicer;
use App\Support\Files\PipelineTemporaryFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * @phpstan-type UsageSnapshot array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int}
 * @phpstan-type WholeEstimate array{ordinal: int, estimate_number: string|null, passport: array<string, mixed>, currency: string, base_year: int|null, price_level: string|null, regulatory_base: string|null, rows_expected: int, next_row_index: int, row_range_size: int, rows: list<array<string, mixed>>, incomplete_items: list<array<string, mixed>>, failed_ranges: list<array{row_index: int, row_range_size: int}>, rows_done: bool, totals: array<string, mixed>|null, totals_done: bool}
 * @phpstan-type WholeProgress array{estimates: list<WholeEstimate>, passport_done: bool, usage?: UsageSnapshot, file_uri?: string, file_expires_at?: string}
 * @phpstan-type DiscoveryState array{next_window_index: int, markers: list<array<string, mixed>>, done: bool}
 * @phpstan-type Segment array{ordinal: int, segment_page_start: int, segment_page_end: int, title?: string}
 * @phpstan-type WindowEstimate array{ordinal: int, estimate_number: string|null, passport: array<string, mixed>, currency: string, base_year: int|null, price_level: string|null, regulatory_base: string|null, rows_expected: int, segment_page_start: int, segment_page_end: int, next_page: int, rows: list<array<string, mixed>>, incomplete_items: list<array<string, mixed>>, rows_done: bool, totals: array<string, mixed>|null, totals_done: bool, passport_done: bool}
 * @phpstan-type WindowProgress array{route: 'windowed', total_pages: int, discovery: DiscoveryState, segments: list<Segment>|null, estimates: list<WindowEstimate>, usage?: UsageSnapshot}
 *
 * Оркестратор полного извлечения смет (D3/D29/D30/D31/D40/D42/D43, `docs/specs/v2/
 * 08-estimate-extraction.md` §4). Два top-level пути: `runWholeDocument()` (row-range по целому
 * кэшированному документу, D3, основной) и `runWindowed()` (трёхстадийный discovery→сегменты→
 * per-segment разбор, D30, P6.4, для документов сверх лимитов маршрута) — preflight (D29)
 * выбирает путь ДО загрузки, `run()` делегирует по `PreflightDecision::route`.
 *
 * Explicit context cache (`SupportsContextCaching`, ТЗ 04 §2.1) СОЗНАТЕЛЬНО не подключается
 * здесь (Known Deviations): Gemini `cachedContent` несовместим с повторной отправкой
 * `system_instruction` в том же запросе (докблок `CacheableContent`), а транспорт (P3.2,
 * `PrismGeminiProvider`) не решает эту несовместимость — подключать без правки транспорта
 * означало бы либо сломанные запросы, либо расширение Files за пределы P6.1. Путь работает
 * ИСКЛЮЧИТЕЛЬНО на implicit-кэшировании (D8 default): системная часть — стабильный префикс
 * между вызовами, файл пересылается на каждый вызов (`ExtractionSession::cacheHandle` всегда
 * `null` в этой реализации, поле оставлено в контракте для будущего explicit-подключения).
 *
 * Checkpoint-requeue (D31/D39): «единица прогресса» — один вызов `ChunkSource`/`WindowedChunkSource`
 * (паспорт/строки/итоги/discovery-окно). После `config('ai.extraction.checkpoint_ranges')` единиц
 * ИЛИ `config('ai.extraction.checkpoint_seconds')` секунд с начала ТЕКУЩЕГО запуска — прогресс
 * персистится ОТДЕЛЬНОЙ транзакцией, `EstimateExtractionJob` ре-диспатчится `afterCommit()`,
 * текущий запуск завершается успешно (`return`, не exception).
 *
 * Оконный путь (D30/D42/D43, P6.4) — второй top-level метод `runWindowed()`, СВОЯ
 * progress-схема, ПЕРЕИСПОЛЬЗУЕТ `checkpointDue()`/`checkpoint()` (не завязаны на форму
 * `estimates`-аккумулятора row-range пути).
 */
final readonly class ExtractionOrchestrator
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private MarkDocumentUnprocessableAction $markDocumentUnprocessable,
        private AnalysisStoreRepository $analyses,
        private LlmRouteResolver $routes,
        private ExtractionPreflight $preflight,
        private ChunkSource $chunkSource,
        private WindowedChunkSource $windowedChunkSource,
        private SegmentAssembler $segmentAssembler,
        private PdfPageSlicer $pageSlicer,
        private EstimatePersister $persister,
        private RepresentationResolver $representations,
        private UsageSnapshotRecorder $usageSnapshot,
    ) {}

    /**
     * @return bool true — checkpoint: прогресс персистирован, вызывающий job должен
     *              self-dispatch-нуть продолжение ПОСЛЕ снятия лиза (release-then-dispatch,
     *              `EstimateExtractionJob::afterRelease()`); false — прогон завершён терминально
     *              (успех/unprocessable) либо не начинался (unreadable-формат).
     *
     * `$forceKind` — сейм принуждения представления (P6.1, D50): `FormatBenchRunner` подаёт им
     * ИМЕННО измеряемый вариант входа. Дефолт `null` сохраняет сегодняшнее поведение байт-в-байт —
     * ничего в логике `run()` кроме прокидывания параметра в резолвер не меняется.
     */
    public function run(Document $document, Analysis $analysis, ?RepresentationKind $forceKind = null): bool
    {
        // The budget starts before file resolution/preflight. Starting the stopwatch only after
        // that work could permit a 150-second provider call when too little job time remains.
        $runStartedAt = microtime(true);

        $this->analyses->update($analysis, [
            'status' => AnalysisStatus::Running,
            'started_at' => $analysis->started_at ?? now(),
        ]);

        $version = $analysis->documentVersion;

        if ($version === null) {
            throw new RuntimeException('Извлечение требует зафиксированную версию документа.');
        }
        $effective = $this->resolveEffectiveFile($version, $forceKind);

        if ($effective === null) {
            $this->markUnprocessable($document, $analysis, UnprocessableReason::UnreadableFormat, "формат «{$version->extension}» не поддержан извлечением");

            return false;
        }

        $disk = Storage::disk($effective['disk']);
        $localPath = PipelineTemporaryFile::create(prefix: 'extraction_', extension: $effective['extension']);
        file_put_contents($localPath, $disk->get($effective['storagePath']));

        try {
            // Единый маршрут (purpose=extract_rows) на ВЕСЬ прогон — паспорт/итоги идут по нему
            // же (Known Deviations): D4 маршрутизирует все extract_* на одну модель, а Files/
            // explicit-cache handle провайдер-специфичны — резолвить 3 отдельных маршрута под
            // один и тот же документ добавило бы риск смешать handle разных провайдеров без
            // реальной сегодняшней потребности (YAGNI, единственный driver — gemini).
            $route = $this->routes->resolve(
                AiDocumentType::from($analysis->document_type ?? AiDocumentType::Estimate->value),
                EstimateLlmPurpose::ExtractRows,
            );

            $decision = $this->preflight->decide($localPath, $effective['extension'], $route);

            if ($decision->isUnprocessable()) {
                $reason = $decision->unprocessableReason;

                if ($reason === null) {
                    throw new RuntimeException('Непроходимое решение preflight обязано нести причину.');
                }

                $this->markUnprocessable(
                    $document,
                    $analysis,
                    $reason,
                    "лимит провайдера: {$reason->value}",
                );

                return false;
            }

            if ($decision->route === ExtractionRoute::Windowed) {
                return $this->runWindowed(
                    document: $document,
                    analysis: $analysis,
                    localPath: $localPath,
                    route: $route,
                    runStartedAt: $runStartedAt,
                );
            }

            return $this->runWholeDocument(
                document: $document,
                analysis: $analysis,
                localPath: $localPath,
                effective: $effective,
                route: $route,
                runStartedAt: $runStartedAt,
            );
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * @param  array{storagePath: string, extension: string, mime: string}  $effective
     */
    private function runWholeDocument(Document $document, Analysis $analysis, string $localPath, array $effective, ResolvedRoute $route, float $runStartedAt): bool
    {
        $session = $this->buildSession($analysis, $localPath, $effective['mime'], $route);

        $checkpointRanges = $this->intConfig('ai.extraction.checkpoint_ranges', 20);
        $checkpointSeconds = $this->intConfig('ai.extraction.checkpoint_seconds', 120);
        $rowRangeSize = $this->intConfig('ai.extraction.row_range_size', 30);
        $minRowRangeSize = $this->intConfig('ai.extraction.min_row_range_size', 5);
        $maxEstimates = $this->intConfig('ai.extraction.max_estimates_per_document', 20);

        $unitsThisRun = 0;

        while (true) {
            if ($this->checkpointDue($unitsThisRun, $runStartedAt, $checkpointRanges, $checkpointSeconds)) {
                $this->checkpoint($analysis, $session);

                return true;
            }

            $progress = $session->progress;
            /** @var WholeProgress $progress */
            $estimates = $progress['estimates'];
            $currentIndex = $this->firstUnfinishedIndex($estimates);

            if ($currentIndex === null) {
                if ($progress['passport_done'] === true || count($estimates) >= $maxEstimates) {
                    break;
                }

                $ordinal = count($estimates) + 1;
                $known = array_values(array_filter(array_column($estimates, 'estimate_number')));

                $result = $this->chunkSource->fetchPassport($analysis->id, $route, $session, $ordinal, $known);
                $session = $session->withUsage($result->usage);
                $unitsThisRun++;

                if ($result->data === null) {
                    $progress['passport_done'] = true;
                    $session = $session->withProgress($progress);

                    continue;
                }

                $passport = $result->data;

                if (! $passport instanceof PassportResponse) {
                    throw new RuntimeException('Источник passport обязан вернуть PassportResponse.');
                }
                $estimates[] = [
                    'ordinal' => $ordinal,
                    'estimate_number' => $passport->estimate_number,
                    'passport' => $passport->passport->toArray(),
                    'currency' => $passport->currency,
                    'base_year' => $passport->base_year,
                    'price_level' => $passport->price_level,
                    'regulatory_base' => $passport->regulatory_base,
                    'rows_expected' => $passport->rows_expected,
                    'next_row_index' => 1,
                    'row_range_size' => $rowRangeSize,
                    'rows' => [],
                    'incomplete_items' => [],
                    'failed_ranges' => [],
                    'rows_done' => false,
                    'totals' => $passport->totals?->toArray(),
                    'totals_done' => $passport->totals !== null,
                ];

                $progress['estimates'] = $estimates;
                $session = $session->withProgress($progress);

                continue;
            }

            $estimate = $estimates[$currentIndex];

            if ($estimate['rows_done'] === false) {
                try {
                    $result = $this->chunkSource->fetchRows(
                        $analysis->id,
                        $route,
                        $session,
                        $estimate['ordinal'],
                        $estimate['next_row_index'],
                        $estimate['row_range_size'],
                    );
                } catch (MaxTokensExceededException) {
                    $unitsThisRun++;

                    if ($estimate['row_range_size'] <= $minRowRangeSize) {
                        // Диапазон непроходим даже минимальным окном — помечаем failed и
                        // сдвигаемся дальше вместо бесконечного повтора того же row_index
                        // (Code Guidance P6.1: «LLM упал на диапазоне — остальные не теряются»).
                        $estimates[$currentIndex]['failed_ranges'][] = [
                            'row_index' => $estimate['next_row_index'],
                            'row_range_size' => $estimate['row_range_size'],
                        ];
                        $estimates[$currentIndex]['next_row_index'] = $estimate['next_row_index'] + $estimate['row_range_size'];
                        $estimates[$currentIndex]['row_range_size'] = $rowRangeSize;
                    } else {
                        $estimates[$currentIndex]['row_range_size'] = max($minRowRangeSize, intdiv($estimate['row_range_size'], 2));
                    }

                    $progress['estimates'] = $estimates;
                    $session = $session->withProgress($progress);

                    continue;
                }

                $session = $session->withUsage($result->usage);
                $unitsThisRun++;

                /** @var RowsChunkResponse $rows */
                $rows = $result->data;

                if ($rows->rows === []) {
                    $estimates[$currentIndex]['rows_done'] = true;
                } else {
                    foreach ($rows->rows as $row) {
                        $estimates[$currentIndex]['rows'][] = $row->toArray();
                    }

                    $estimates[$currentIndex]['next_row_index'] = end($rows->rows)->row_index + 1;
                }

                foreach ($rows->incomplete_items as $incomplete) {
                    $estimates[$currentIndex]['incomplete_items'][] = $incomplete->toArray();
                }

                $progress['estimates'] = $estimates;
                $session = $session->withProgress($progress);

                continue;
            }

            // rows_done === true, totals_done === false (иначе индекс не считался бы «незавершённым»)
            $result = $this->chunkSource->fetchTotals($analysis->id, $route, $session, $estimate['ordinal']);
            $session = $session->withUsage($result->usage);
            $unitsThisRun++;

            /** @var TotalsResponse $totals */
            $totals = $result->data;
            $estimates[$currentIndex]['totals'] = $totals->totals->toArray();
            $estimates[$currentIndex]['totals_done'] = true;

            $progress['estimates'] = $estimates;
            $session = $session->withProgress($progress);
        }

        $this->finalizeSuccess($document, $analysis, $session, $route);

        return false;
    }

    /**
     * Оконный путь (D30/D42/D43, `docs/specs/v2/08-estimate-extraction.md` §4.2). Три стадии,
     * КАЖДАЯ страница/окно/сегмент-шаг — своя checkpoint-единица (D31): (1) discovery — весь
     * документ режется на страничные окна, каждое → `WindowedChunkSource::fetchDiscoveryWindow()`,
     * маркеры копятся в `progress['discovery']['markers']`; (2) когда все окна пройдены — ОДИН
     * детерминированный проход `SegmentAssembler::assemble()` (НЕ LLM-вызов, НЕ
     * checkpoint-единица) строит `progress['segments']`/инициализирует `progress['estimates']`;
     * (3) на каждый сегмент — паспорт → строки окнами страниц до `next_page > segment_page_end`
     * (терминатор ЧИСТО страничный, не самоотчёт модели) → итоги по хвосту. Результат кладётся в
     * ТОТ ЖЕ `progress['estimates']`-shape, что `runWholeDocument()` — `finalizeSuccess()`/
     * `EstimatePersister` (P6.3) отрабатывают без изменений.
     */
    private function runWindowed(Document $document, Analysis $analysis, string $localPath, ResolvedRoute $route, float $runStartedAt): bool
    {
        $session = $this->buildWindowedSession($analysis, $localPath);
        $deadlineAt = $runStartedAt + $this->intConfig('ai.pipeline.operation_budgets.extraction.job_timeout_seconds', 300);

        $checkpointRanges = $this->intConfig('ai.extraction.checkpoint_ranges', 20);
        $checkpointSeconds = $this->intConfig('ai.extraction.checkpoint_seconds', 120);
        $windowPages = $this->intConfig('ai.extraction.window_pages', 3);
        $windowOverlapPages = $this->intConfig('ai.extraction.window_overlap_pages', 1);
        $totalsTailPages = $this->intConfig('ai.extraction.totals_tail_pages', 2);

        $discoveryRoute = $this->routes->resolve(
            AiDocumentType::from($analysis->document_type ?? AiDocumentType::Estimate->value),
            EstimateLlmPurpose::Discovery,
        );

        $unitsThisRun = 0;

        while (true) {
            if ($this->checkpointDue($unitsThisRun, $runStartedAt, $checkpointRanges, $checkpointSeconds)) {
                $this->checkpoint($analysis, $session);

                return true;
            }

            $progress = $session->progress;
            /** @var WindowProgress $progress */
            if ($progress['discovery']['done'] === false) {
                $windowIndex = $progress['discovery']['next_window_index'];
                $pageStart = $windowIndex * ($windowPages - $windowOverlapPages) + 1;

                if ($pageStart > $progress['total_pages']) {
                    $progress['discovery']['done'] = true;
                    $session = $session->withProgress($progress);

                    continue;
                }

                $pageEnd = min($pageStart + $windowPages - 1, $progress['total_pages']);

                $result = $this->windowedChunkSource->fetchDiscoveryWindow(
                    analysisId: $analysis->id,
                    discoveryRoute: $discoveryRoute,
                    localPath: $localPath,
                    windowIndex: $windowIndex,
                    pageStart: $pageStart,
                    pageEnd: $pageEnd,
                    deadlineAt: $deadlineAt,
                );
                $session = $session->withUsage($result->usage);
                $unitsThisRun++;

                /** @var DiscoveryResponse $discovery */
                $discovery = $result->data;

                foreach ($discovery->markers as $marker) {
                    $progress['discovery']['markers'][] = $marker->toArray();
                }

                $progress['discovery']['next_window_index'] = $windowIndex + 1;

                if ($pageEnd >= $progress['total_pages']) {
                    $progress['discovery']['done'] = true;
                }

                $session = $session->withProgress($progress);

                continue;
            }

            if ($progress['segments'] === null) {
                $markers = array_map(
                    static fn (array $marker): DiscoveryMarkerLine => DiscoveryMarkerLine::from($marker),
                    $progress['discovery']['markers'],
                );

                $segments = $this->segmentAssembler->assemble($markers, $progress['total_pages']);

                $progress['segments'] = $segments;
                $progress['estimates'] = array_map(static fn (array $segment): array => [
                    'ordinal' => $segment['ordinal'],
                    'estimate_number' => null,
                    'passport' => null,
                    'currency' => 'RUB',
                    'base_year' => null,
                    'price_level' => null,
                    'regulatory_base' => null,
                    'rows_expected' => 0,
                    'segment_page_start' => $segment['page_start'],
                    'segment_page_end' => $segment['page_end'],
                    'passport_done' => false,
                    'next_page' => $segment['page_start'],
                    'rows' => [],
                    'incomplete_items' => [],
                    'rows_done' => false,
                    'totals' => null,
                    'totals_done' => false,
                ], $segments);

                $session = $session->withProgress($progress);

                // Сборка сегментов — детерминированная функция, не LLM-вызов, НЕ checkpoint-единица.
                continue;
            }

            $estimates = $progress['estimates'];
            $currentIndex = $this->firstUnfinishedWindowedIndex($estimates);

            if ($currentIndex === null) {
                break;
            }

            $estimate = $estimates[$currentIndex];
            $segmentTitle = $progress['segments'][$currentIndex]['title'] ?? null;

            if ($estimate['passport_done'] === false) {
                $result = $this->windowedChunkSource->fetchSegmentPassport(
                    analysisId: $analysis->id,
                    route: $route,
                    localPath: $localPath,
                    segmentOrdinal: $estimate['ordinal'],
                    pageStart: $estimate['segment_page_start'],
                    pageEnd: $estimate['segment_page_end'],
                    titleHint: $segmentTitle,
                    deadlineAt: $deadlineAt,
                );
                $session = $session->withUsage($result->usage);
                $unitsThisRun++;

                /** @var PassportResponse $passport */
                $passport = $result->data;

                $estimates[$currentIndex]['estimate_number'] = $passport->estimate_number;
                $estimates[$currentIndex]['passport'] = $passport->passport->toArray();
                $estimates[$currentIndex]['currency'] = $passport->currency;
                $estimates[$currentIndex]['base_year'] = $passport->base_year;
                $estimates[$currentIndex]['price_level'] = $passport->price_level;
                $estimates[$currentIndex]['regulatory_base'] = $passport->regulatory_base;
                $estimates[$currentIndex]['rows_expected'] = $passport->rows_expected;
                $estimates[$currentIndex]['passport_done'] = true;

                if ($passport->totals !== null) {
                    $estimates[$currentIndex]['totals'] = $passport->totals->toArray();
                    $estimates[$currentIndex]['totals_done'] = true;
                }

                $progress['estimates'] = $estimates;
                $session = $session->withProgress($progress);

                continue;
            }

            if ($estimate['rows_done'] === false) {
                $windowPageEnd = min($estimate['next_page'] + $windowPages - 1, $estimate['segment_page_end']);

                $result = $this->windowedChunkSource->fetchSegmentRows(
                    analysisId: $analysis->id,
                    route: $route,
                    localPath: $localPath,
                    segmentOrdinal: $estimate['ordinal'],
                    pageStart: $estimate['next_page'],
                    pageEnd: $windowPageEnd,
                    deadlineAt: $deadlineAt,
                );
                $session = $session->withUsage($result->usage);
                $unitsThisRun++;

                /** @var RowsChunkResponse $rows */
                $rows = $result->data;

                foreach ($rows->rows as $row) {
                    $estimates[$currentIndex]['rows'][] = $row->toArray();
                }

                foreach ($rows->incomplete_items as $incomplete) {
                    $estimates[$currentIndex]['incomplete_items'][] = $incomplete->toArray();
                }

                // Терминатор ЧИСТО страничный (не самоотчёт модели, Code Guidance P6.4) — модель
                // окна не знает границ сегмента заранее, в отличие от row-range.
                $nextPage = $windowPageEnd - $windowOverlapPages + 1;
                $estimates[$currentIndex]['next_page'] = $nextPage;

                if ($nextPage > $estimate['segment_page_end'] || $windowPageEnd >= $estimate['segment_page_end']) {
                    $estimates[$currentIndex]['rows_done'] = true;
                }

                $progress['estimates'] = $estimates;
                $session = $session->withProgress($progress);

                continue;
            }

            // rows_done === true, totals_done === false (иначе индекс не считался бы «незавершённым»)
            $tailStart = max($estimate['segment_page_start'], $estimate['segment_page_end'] - $totalsTailPages + 1);

            $result = $this->windowedChunkSource->fetchSegmentTotals(
                analysisId: $analysis->id,
                route: $route,
                localPath: $localPath,
                segmentOrdinal: $estimate['ordinal'],
                pageStart: $tailStart,
                pageEnd: $estimate['segment_page_end'],
                deadlineAt: $deadlineAt,
            );
            $session = $session->withUsage($result->usage);
            $unitsThisRun++;

            /** @var TotalsResponse $totals */
            $totals = $result->data;
            $estimates[$currentIndex]['totals'] = $totals->totals->toArray();
            $estimates[$currentIndex]['totals_done'] = true;

            $progress['estimates'] = $estimates;
            $session = $session->withProgress($progress);
        }

        $this->finalizeSuccess($document, $analysis, $session, $route);

        return false;
    }

    private function checkpointDue(int $unitsThisRun, float $runStartedAt, int $checkpointRanges, int $checkpointSeconds): bool
    {
        $jobTimeout = $this->intConfig('ai.pipeline.operation_budgets.extraction.job_timeout_seconds', 300);
        $nextCall = $this->intConfig('ai.pipeline.operation_budgets.extraction.max_next_call_seconds', 150);
        $reserve = $this->intConfig('ai.pipeline.operation_budgets.extraction.persistence_release_reserve_seconds', 30);

        return $unitsThisRun >= $checkpointRanges
            || (microtime(true) - $runStartedAt) >= $checkpointSeconds
            || ($runStartedAt + $jobTimeout - microtime(true)) <= $nextCall + $reserve;
    }

    /** @param list<WholeEstimate> $estimates */
    private function firstUnfinishedIndex(array $estimates): ?int
    {
        foreach ($estimates as $index => $estimate) {
            if ($estimate['rows_done'] === false || $estimate['totals_done'] === false) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<WindowEstimate> $estimates */
    private function firstUnfinishedWindowedIndex(array $estimates): ?int
    {
        foreach ($estimates as $index => $estimate) {
            if ($estimate['passport_done'] === false || $estimate['rows_done'] === false || $estimate['totals_done'] === false) {
                return $index;
            }
        }

        return null;
    }

    private function buildSession(Analysis $analysis, string $localPath, string $mime, ResolvedRoute $route): ExtractionSession
    {
        $progress = $this->progressArray($analysis);
        $progress += ['estimates' => [], 'passport_done' => false];

        $fileHandle = $this->resolveFileHandle($progress, $localPath, $mime, $route);

        // Usage накапливается по всем self-requeue-запускам через `progress['usage']` (не
        // сбрасывается на каждом прогоне) — иначе `analysis.result.meta.usage` в финале
        // отражал бы только ПОСЛЕДНИЙ прогон, а не суммарные затраты анализа.
        $usage = isset($progress['usage']) && is_array($progress['usage'])
            ? $this->usageFromArray($this->usageSnapshot($progress['usage']))
            : new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0);

        return new ExtractionSession(
            fileHandle: $fileHandle,
            cacheHandle: null,
            usage: $usage,
            progress: $progress,
        );
    }

    /**
     * Оконный путь (D42/D43, P6.4) — `fileHandle` ВСЕГДА `null` (документ целиком никогда не
     * грузится, каждый `WindowedChunkSource`-вызов грузит свой срез самостоятельно). Дефолтные
     * ключи progress-схемы инициализируются ОДИН раз (первый прогон, `'route'` ещё не установлен
     * в persisted-прогрессе) — последующие self-requeue-запуски читают уже сохранённое состояние
     * из `analysis.progress` как есть (`progressArray()`).
     */
    private function buildWindowedSession(Analysis $analysis, string $localPath): ExtractionSession
    {
        $progress = $this->progressArray($analysis);

        if (! isset($progress['route'])) {
            $progress['route'] = 'windowed';
            $progress['total_pages'] = $this->pageSlicer->pageCount($localPath);
            $progress['discovery'] = ['next_window_index' => 0, 'markers' => [], 'done' => false];
            $progress['segments'] = null;
            $progress['estimates'] = [];
        }

        $usage = isset($progress['usage']) && is_array($progress['usage'])
            ? $this->usageFromArray($this->usageSnapshot($progress['usage']))
            : new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0);

        return new ExtractionSession(
            fileHandle: null,
            cacheHandle: null,
            usage: $usage,
            progress: $progress,
        );
    }

    /**
     * @param  array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int}  $usage
     */
    private function usageFromArray(array $usage): LlmUsage
    {
        return new LlmUsage(
            promptTokens: (int) $usage['prompt_tokens'],
            completionTokens: (int) $usage['completion_tokens'],
            cachedTokens: (int) $usage['cached_tokens'],
            totalTokens: (int) $usage['total_tokens'],
        );
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int}
     */
    private function usageToArray(LlmUsage $usage): array
    {
        return [
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cached_tokens' => $usage->cachedTokens,
            'total_tokens' => $usage->totalTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressArray(Analysis $analysis): array
    {
        $progress = $analysis->progress;

        if ($progress === null) {
            return [];
        }

        return $progress->all();
    }

    /**
     * @param  array<mixed, mixed>  $usage
     * @return UsageSnapshot
     */
    private function usageSnapshot(array $usage): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'cached_tokens', 'total_tokens'] as $key) {
            if (! isset($usage[$key]) || ! is_int($usage[$key])) {
                throw new RuntimeException("Некорректный persisted usage: {$key} обязан быть integer.");
            }
        }

        /** @var UsageSnapshot $usage */
        return $usage;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException("Конфигурация {$key} обязана быть integer.");
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function resolveFileHandle(array &$progress, string $localPath, string $mime, ResolvedRoute $route): LlmFileHandle
    {
        $uri = $progress['file_uri'] ?? null;
        $expiresAt = $progress['file_expires_at'] ?? null;

        if (is_string($uri) && is_string($expiresAt) && CarbonImmutable::parse($expiresAt)->isFuture()) {
            return new LlmFileHandle(uri: $uri, expiresAt: CarbonImmutable::parse($expiresAt));
        }

        if (! $route->provider instanceof SupportsFileUpload) {
            throw new RuntimeException('Провайдер маршрута извлечения не поддерживает Files API.');
        }

        $handle = $route->provider->uploadFile($localPath, $mime);

        $progress['file_uri'] = $handle->uri;
        $progress['file_expires_at'] = $handle->expiresAt->toIso8601String();

        return $handle;
    }

    /**
     * Персистирует прогресс (вкл. суммарный usage) ОДНОЙ транзакцией; self-dispatch продолжения
     * — задача вызывающего job'а (`EstimateExtractionJob::afterRelease()`), СТРОГО после
     * снятия лиза (release-then-dispatch, D39, review-фикс P6.1) — здесь лиз ещё держит
     * ТЕКУЩИЙ запуск, диспатч отсюда создавал бы гонку с конкурентным воркером продолжения.
     */
    private function checkpoint(Analysis $analysis, ExtractionSession $session): void
    {
        $progress = $session->progress;
        $progress['usage'] = $this->usageToArray($session->usage);

        DB::transaction(function () use ($analysis, $progress): void {
            $this->analyses->update($analysis, [
                'progress' => $progress,
            ]);
        });
    }

    private function finalizeSuccess(Document $document, Analysis $analysis, ExtractionSession $session, ResolvedRoute $route): void
    {
        $result = [
            'estimates' => $session->progress['estimates'],
            'meta' => ['usage' => $this->usageToArray($session->usage)],
        ];

        // Публикация позиций, analysis и document атомарна. Порядок блокировок совпадает
        // с acquireExecutionLease: document → analysis. Старый worker не публикует результат.
        DB::transaction(function () use ($analysis, $document, $result, $route, $session): void {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $lockedAnalysis = Analysis::query()->lockForUpdate()->findOrFail($analysis->id);

            if ($lockedAnalysis->superseded_at !== null
                || ($analysis->hasLeaseToken() && ($lockedDocument->status !== DocumentStatus::Screened
                    || $this->documents->latestVersion($document->id)?->id !== $analysis->document_version_id
                    || ! $analysis->ownsLease()))) {
                throw new RuntimeException('Extraction ownership was lost before publication.');
            }

            $analysis->result = collect($result);
            $this->persister->persist($analysis, supersededAnalysisId: $analysis->parent_analysis_id);

            $this->usageSnapshot->finalizeAnalysis($analysis, [
                'status' => AnalysisStatus::Succeeded,
                'progress' => $session->progress,
                'result' => $result,
                'provider' => $route->options->provider,
                'model' => $route->options->model,
                'finished_at' => now(),
            ]);

            $this->documents->update($document, ['status' => DocumentStatus::Extracted]);
        });
    }

    private function markUnprocessable(Document $document, Analysis $analysis, UnprocessableReason $reason, string $detail): void
    {
        $this->markDocumentUnprocessable->execute($document, $reason, $detail);

        $this->usageSnapshot->finalizeAnalysis($analysis, [
            'status' => AnalysisStatus::Skipped,
            'result' => ['unprocessable_reason' => $reason->value, 'detail' => $detail],
            'finished_at' => now(),
        ]);
    }

    /**
     * Какое представление версии уходит в LLM, решает {@see RepresentationResolver} (D25, P3.1);
     * здесь остаётся ТОЛЬКО правило извлечения «что мы вообще умеем отправить»: PDF и изображения
     * (тот же приём, что `ScreeningProcessor::resolveEffectiveFile`). Текстовые/XML-варианты
     * (форсированные бенчмарком P6.1) этот фильтр СОЗНАТЕЛЬНО не пропускает — сегодняшний
     * пайплайн умеет отправлять в LLM только PDF/изображение, и принуждение не расширяет эту
     * способность (Implementation Rules P6.1: «ничего больше в своей логике не меняет»); реальный
     * прогон такого варианта честно завершается `unprocessable(unreadable_format)` — это само по
     * себе измеримый исход ячейки, а не дефект.
     *
     * @return array{storagePath: string, disk: string, extension: string, mime: string}|null
     */
    private function resolveEffectiveFile(DocumentVersion $version, ?RepresentationKind $forceKind = null): ?array
    {
        $effective = $this->representations->forLlm($version, $forceKind);

        if ($effective === null) {
            return null;
        }

        if ($effective['extension'] === 'pdf' || str_starts_with($effective['mime'], 'image/')) {
            return $effective;
        }

        return null;
    }
}
