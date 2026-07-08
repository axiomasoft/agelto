<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Ai\Estimate\TotalsResponse;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\ExtractionRoute;
use App\Enums\Procurement\LlmPurpose;
use App\Enums\Procurement\UnprocessableReason;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Services\Llm\AiRouteResolver;
use App\Services\Llm\Contracts\SupportsFileUpload;
use App\Services\Llm\Dto\LlmFileHandle;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Procurement\Extraction\Exceptions\MaxTokensExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Оркестратор полного извлечения смет (D3/D29/D30/D31/D40, `docs/specs/v2/
 * 08-estimate-extraction.md` §4). Основной путь (row-range по целому кэшированному документу) —
 * ЕДИНСТВЕННЫЙ исполняемый здесь; оконный путь (D30, P6.4) preflight решает/помечает, но не
 * исполняет (D40) — при выборе `ExtractionRoute::Windowed` бросает исключение (job-level retry,
 * не unprocessable — сознательный выбор оператора включить недостроенный путь раньше времени).
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
 * Checkpoint-requeue (D31/D39): «единица прогресса» — один вызов `ChunkSource` (паспорт/строки/
 * итоги). После `config('ai.extraction.checkpoint_ranges')` единиц ИЛИ
 * `config('ai.extraction.checkpoint_seconds')` секунд с начала ТЕКУЩЕГО запуска — прогресс
 * персистится ОТДЕЛЬНОЙ транзакцией, `EstimateExtractionJob` ре-диспатчится `afterCommit()`,
 * текущий запуск завершается успешно (`return`, не exception).
 */
final readonly class ExtractionOrchestrator
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private AnalysisStoreRepository $analyses,
        private AiRouteResolver $routes,
        private ExtractionPreflight $preflight,
        private ChunkSource $chunkSource,
        private EstimatePersister $persister,
    ) {}

    /**
     * @return bool true — checkpoint: прогресс персистирован, вызывающий job должен
     *              self-dispatch-нуть продолжение ПОСЛЕ снятия лиза (release-then-dispatch,
     *              `EstimateExtractionJob::afterRelease()`); false — прогон завершён терминально
     *              (успех/unprocessable) либо не начинался (unreadable-формат).
     */
    public function run(Document $document, Analysis $analysis): bool
    {
        $this->analyses->update($analysis, [
            'status' => AnalysisStatus::Running,
            'started_at' => $analysis->started_at ?? now(),
        ]);

        $version = $analysis->documentVersion;
        $effective = $this->resolveEffectiveFile($version);

        if ($effective === null) {
            $this->markUnprocessable($document, $analysis, UnprocessableReason::UnreadableFormat, "формат «{$version->extension}» не поддержан извлечением");

            return false;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localPath = tempnam(sys_get_temp_dir(), 'extraction_').'.'.$effective['extension'];
        file_put_contents($localPath, $disk->get($effective['storagePath']));

        try {
            // Единый маршрут (purpose=extract_rows) на ВЕСЬ прогон — паспорт/итоги идут по нему
            // же (Known Deviations): D4 маршрутизирует все extract_* на одну модель, а Files/
            // explicit-cache handle провайдер-специфичны — резолвить 3 отдельных маршрута под
            // один и тот же документ добавило бы риск смешать handle разных провайдеров без
            // реальной сегодняшней потребности (YAGNI, единственный driver — gemini).
            $route = $this->routes->resolve($analysis->document_type ?? 'estimate', LlmPurpose::ExtractRows);

            $decision = $this->preflight->decide($localPath, $effective['extension'], $route);

            if ($decision->isUnprocessable()) {
                $this->markUnprocessable(
                    $document,
                    $analysis,
                    $decision->unprocessableReason,
                    "лимит провайдера: {$decision->unprocessableReason->value}",
                );

                return false;
            }

            if ($decision->route === ExtractionRoute::Windowed) {
                throw new RuntimeException(
                    'Оконный путь извлечения (WindowedChunkSource) ещё не реализован — появится в P6.4 '
                    .'(config: ai.extraction.windowed_enabled).',
                );
            }

            return $this->runWholeDocument($document, $analysis, $localPath, $effective, $route);
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * @param  array{storagePath: string, extension: string, mime: string}  $effective
     */
    private function runWholeDocument(Document $document, Analysis $analysis, string $localPath, array $effective, ResolvedRoute $route): bool
    {
        $session = $this->buildSession($analysis, $localPath, $effective['mime'], $route);

        $checkpointRanges = (int) config('ai.extraction.checkpoint_ranges', 20);
        $checkpointSeconds = (int) config('ai.extraction.checkpoint_seconds', 240);
        $rowRangeSize = (int) config('ai.extraction.row_range_size', 30);
        $minRowRangeSize = (int) config('ai.extraction.min_row_range_size', 5);
        $maxEstimates = (int) config('ai.extraction.max_estimates_per_document', 20);

        $runStartedAt = microtime(true);
        $unitsThisRun = 0;

        while (true) {
            if ($unitsThisRun > 0 && $this->checkpointDue($unitsThisRun, $runStartedAt, $checkpointRanges, $checkpointSeconds)) {
                $this->checkpoint($analysis, $session);

                return true;
            }

            $progress = $session->progress;
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
                $estimates[] = [
                    'ordinal' => $ordinal,
                    'estimate_number' => $passport->estimate_number,
                    'passport' => $passport->passport->toArray(),
                    'currency' => $passport->currency,
                    'base_year' => $passport->base_year,
                    'price_level' => $passport->price_level,
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

        $this->finalizeSuccess($document, $analysis, $session);

        return false;
    }

    private function checkpointDue(int $unitsThisRun, float $runStartedAt, int $checkpointRanges, int $checkpointSeconds): bool
    {
        return $unitsThisRun >= $checkpointRanges || (microtime(true) - $runStartedAt) >= $checkpointSeconds;
    }

    /**
     * @param  list<array<string, mixed>>  $estimates
     */
    private function firstUnfinishedIndex(array $estimates): ?int
    {
        foreach ($estimates as $index => $estimate) {
            if ($estimate['rows_done'] === false || $estimate['totals_done'] === false) {
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
            ? $this->usageFromArray($progress['usage'])
            : new LlmUsage(promptTokens: 0, completionTokens: 0, cachedTokens: 0, totalTokens: 0);

        return new ExtractionSession(
            fileHandle: $fileHandle,
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

        return $progress instanceof Collection ? $progress->toArray() : (array) $progress;
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

    private function finalizeSuccess(Document $document, Analysis $analysis, ExtractionSession $session): void
    {
        $result = [
            'estimates' => $session->progress['estimates'],
            'meta' => ['usage' => $this->usageToArray($session->usage)],
        ];

        // Персист в prc_estimate_* (P6.3, EstimateStitcher+EstimateValidator, P6.2) — СТРОГО ДО
        // финализации статусов analysis/document (review-фикс P6.3 HIGH, было наоборот): persist()
        // читает `analysis.result` из ПЕРЕДАННОГО объекта (атрибут выставлен in-memory, кастуется
        // на чтение — `AsCollection`), не из БД, поэтому порядок безопасен без промежуточного
        // save(). Если `persist()` бросит исключение (транзиентный DB-сбой, `Storage::put`,
        // per-estimate constraint race), analysis/document остаются в промежуточном статусе
        // (`running`/`screened`) — `recordFailure()` → job retry → `statusGuardPassed()` пропустит
        // повтор, а не станет молчаливым no-op на уже `extracted` документе без персиста
        // (недостижимая идемпотентность — Scope Included P6.3 «идемпотентный перезапуск»).
        $analysis->result = $result;
        $this->persister->persist($analysis);

        $this->analyses->update($analysis, [
            'status' => AnalysisStatus::Succeeded,
            'progress' => $session->progress,
            'result' => $result,
            'finished_at' => now(),
        ]);

        // Документ доводится до extracted здесь (Implementation Rules P6.1 — финализация статуса
        // анализа/документа обязательна в любом исходе, v1-урок «вечный analyzing») ПОСЛЕ
        // успешного персиста — раньше документ мог перейти в extracted, даже если персист упал.
        $this->documents->update($document, ['status' => DocumentStatus::Extracted]);
    }

    private function markUnprocessable(Document $document, Analysis $analysis, UnprocessableReason $reason, string $detail): void
    {
        $this->documents->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => "unprocessable:{$reason->value}: {$detail}",
        ]);

        $this->analyses->update($analysis, [
            'status' => AnalysisStatus::Skipped,
            'result' => ['unprocessable_reason' => $reason->value, 'detail' => $detail],
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array{storagePath: string, extension: string, mime: string}|null
     */
    private function resolveEffectiveFile(DocumentVersion $version): ?array
    {
        // Конвертированный xlsx (SpreadsheetStrategy, P4.2) — всегда PDF, оригинал игнорируем
        // (тот же приём, что ScreeningProcessor::resolveEffectiveFile, P5.1).
        if ($version->prepared_storage_path !== null) {
            return ['storagePath' => $version->prepared_storage_path, 'extension' => 'pdf', 'mime' => 'application/pdf'];
        }

        if ($version->extension === 'pdf') {
            return ['storagePath' => $version->storage_path, 'extension' => 'pdf', 'mime' => (string) $version->mime];
        }

        if (str_starts_with((string) $version->mime, 'image/')) {
            return ['storagePath' => $version->storage_path, 'extension' => $version->extension, 'mime' => (string) $version->mime];
        }

        return null;
    }
}
