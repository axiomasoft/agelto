<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\CsvBlockIngestor;
use App\Services\Procurement\RunStopGate;
use App\Services\Procurement\SourceMethodFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Оркестрация fresh-прогона (`02-crawling.md` §4.1): цикл блоков листинга до первого
 * известного блока или cap по повтору `dedupSignature`, с heartbeat/курсором на каждом
 * шаге и safety hard-cap от бесконечного цикла. Стоп-гейт (P3.2, D28) и бюджет одного
 * запуска (`crawler.run.checkpoint_seconds`) читаются в ТОЙ ЖЕ точке итерации, что уже
 * делает `$crawler->refresh()` — курсор к моменту любого из этих выходов уже сохранён
 * предыдущей итерацией, повторный поход в БД не добавляется.
 */
final readonly class FreshRunProcessor
{
    public function __construct(
        private SourceMethodFactory $factory,
        private CrawlerRunManager $runs,
        private CsvBlockIngestor $ingestor,
        private RunStopGate $stopGate,
    ) {}

    public function run(Crawler $crawler): Run
    {
        $run = $this->runs->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);
        $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);

        if ($cursor->recoveryScope === null) {
            // New runs snapshot the subject before their first request. A pre-carrier running
            // run can only fall back to the currently validated profile, so persist that choice
            // before it resumes instead of letting a later backlog sweep read mutable settings.
            $cursor = $cursor->withRecoveryScope(RecoveryScope::fromFreshCrawler(crawler: $crawler));

            if (! $this->runs->updateCursor(run: $run, cursor: $cursor)) {
                throw new \RuntimeException('Unable to persist the fresh run recovery scope.');
            }
        } else {
            RecoveryScope::fromPersistedFreshCrawler(crawler: $crawler, payload: $cursor->recoveryScope);
        }

        $previousCompletedRun = $this->runs->lastCompletedRun(crawler: $crawler, excludeRunId: $run->id);
        $this->runs->recordScanGap(run: $run, previousCompletedRun: $previousCompletedRun);

        $blockSize = $this->integerConfig(key: 'crawler.eis.block_size', default: 500);
        $maxBlocks = $this->integerConfig(key: 'crawler.fresh_max_blocks', default: 40);
        $checkpointSeconds = $this->integerConfig(key: 'crawler.run.checkpoint_seconds', default: 240);
        $startedAt = Carbon::now();
        // Порог считается ОДИН раз на старте (Implementation Rule 2): предыдущий завершённый
        // прогон за время обхода не меняется, перечитывание на каждом блоке — лишний запрос.
        $onlyFreshThreshold = $this->resolveOnlyFreshThreshold(crawler: $crawler, previousCompletedRun: $previousCompletedRun);

        try {
            for ($iteration = 0; $iteration < $maxBlocks; $iteration++) {
                $crawler->refresh();

                $stopSignal = $this->stopGate->signal(run: $run);

                if ($stopSignal !== null) {
                    $reason = "Остановлено оператором ({$stopSignal->value}).";
                    match ($stopSignal) {
                        RunStopKind::Pause => $this->runs->pause(run: $run, reason: $reason),
                        RunStopKind::Cancel => $this->runs->cancel(run: $run, reason: $reason),
                    };

                    return $run;
                }

                if ($startedAt->diffInSeconds(Carbon::now()) >= $checkpointSeconds) {
                    $this->runs->recordCheckpoint(run: $run);

                    return $run;
                }

                $sourceMethod = $this->factory->makeWithFallback(crawler: $crawler);
                $batch = $sourceMethod->collectListing(crawler: $crawler, cursor: $cursor);
                $fetchResult = $this->ingestor->ingest(crawler: $crawler, run: $run, batch: $batch);

                if ($fetchResult->oldestUpdateSeen !== null) {
                    $this->runs->recordOldestUpdateSeen(run: $run, candidate: $fetchResult->oldestUpdateSeen);
                }

                // Сравнение — ПОСЛЕ инжеста (Implementation Rule 4): строки блока уже сохранены,
                // останавливаемся, собрав блок, а не выбросив его.
                if ($cursor->lastDedupSignature !== null && $batch->dedupSignature === $cursor->lastDedupSignature) {
                    $this->handleCapHit(crawler: $crawler, run: $run, blockFrom: $cursor->blockFrom);
                    $this->markStopReason(run: $run, reason: 'dedup_signature_repeat');

                    $cursor = $cursor->withReachedKnown();
                    $this->runs->updateCursor(run: $run, cursor: $cursor);
                    $this->runs->complete(run: $run);

                    return $run;
                }

                if ($this->reachedPreviousRun(threshold: $onlyFreshThreshold, fetchResult: $fetchResult)) {
                    $this->markStopReason(run: $run, reason: 'reached_previous_run', stopThresholdAt: $onlyFreshThreshold, oldestUpdateSeen: $fetchResult->oldestUpdateSeen, oldestUpdateSeenHasDayPrecision: $fetchResult->oldestUpdateSeenHasDayPrecision);

                    $cursor = $cursor->withReachedKnown();
                    $this->runs->updateCursor(run: $run, cursor: $cursor);
                    $this->runs->complete(run: $run);

                    return $run;
                }

                if ($fetchResult->rowsTotal === 0) {
                    $this->markStopReason(run: $run, reason: 'source_exhausted');

                    $cursor = $cursor->withReachedKnown();
                    $this->runs->updateCursor(run: $run, cursor: $cursor);
                    $this->runs->complete(run: $run);

                    return $run;
                }

                $cursor = $cursor->afterFreshBlock(blockSize: $blockSize, dedupSignature: $batch->dedupSignature);
                $this->runs->updateCursor(run: $run, cursor: $cursor);
                $this->runs->heartbeat(run: $run);
            }
        } catch (PlatformBudgetExhaustedException) {
            $this->runs->recordCheckpoint(run: $run);

            return $run;
        } catch (Throwable $exception) {
            $this->runs->fail(run: $run, error: $exception->getMessage());

            throw $exception;
        }

        Log::warning(
            message: 'FreshRunProcessor: hard cap reached without stop condition.',
            context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'max_blocks' => $maxBlocks],
        );
        $this->markStopReason(run: $run, reason: 'fresh_max_blocks_checkpoint');
        $this->runs->recordCheckpoint(run: $run);

        return $run;
    }

    /**
     * Порог политики «только актуальные данные» (P3.3, `research/04-p3-runs-design.md` §4):
     * время СТАРТА предыдущего завершённого прогона минус запас из конфига — НЕ `finished_at`
     * (Implementation Rule/Why: интервал, пока шёл предыдущий прогон, он мог не увидеть).
     * Нет предыдущего завершённого прогона → политика не действует (Implementation Rule 3):
     * трактовать отсутствие как «останавливайся сразу» здесь запрещено.
     */
    private function resolveOnlyFreshThreshold(Crawler $crawler, ?Run $previousCompletedRun): ?Carbon
    {
        if ($crawler->stop_policy !== RunStopPolicy::OnlyFresh) {
            return null;
        }

        if ($previousCompletedRun?->started_at === null) {
            return null;
        }

        $marginMinutes = $this->integerConfig(key: 'crawler.fresh.only_fresh_margin_minutes', default: 60);

        return $previousCompletedRun->started_at->clone()->subMinutes($marginMinutes);
    }

    /**
     * Граничный случай «ровно на пороге» (Implementation Rule 5): НЕ ПОЗЖЕ порога = остановка.
     */
    private function reachedPreviousRun(?Carbon $threshold, FetchResult $fetchResult): bool
    {
        if ($threshold === null || $fetchResult->oldestUpdateSeen === null) {
            return false;
        }

        if (! $fetchResult->oldestUpdateSeenHasDayPrecision) {
            return $fetchResult->oldestUpdateSeen->lte($threshold);
        }

        return $fetchResult->oldestUpdateSeen->toDateString() < $threshold->toDateString();
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new \UnexpectedValueException("{$key} must be an integer.");
        }

        return $value;
    }

    /**
     * Все три стоп-условия fresh-прогона пишут РАЗНЫЙ `stats.stop_reason` (Implementation
     * Rule 1) — приёмом `array_merge`, что и `pause()`/`cancel()`, чтобы не затереть соседние
     * ключи (`cap_hit`, `scan_gap_seconds`, `oldest_update_seen`). Мутирует модель В ПАМЯТИ —
     * персистится ближайшим `update()` (`updateCursor()`/`complete()`), второй записи не даёт.
     */
    private function markStopReason(
        Run $run,
        string $reason,
        ?Carbon $stopThresholdAt = null,
        ?Carbon $oldestUpdateSeen = null,
        bool $oldestUpdateSeenHasDayPrecision = false,
    ): void {
        $stats = array_merge($run->stats?->toArray() ?? [], array_filter([
            'stop_reason' => $reason,
            'stop_threshold_at' => $stopThresholdAt?->toIso8601String(),
            'stop_oldest_update_seen' => $oldestUpdateSeen?->toIso8601String(),
            'stop_oldest_update_seen_has_day_precision' => $oldestUpdateSeen === null ? null : $oldestUpdateSeenHasDayPrecision,
        ], static fn (mixed $value): bool => $value !== null));

        $run->stats = collect($stats);
    }

    private function handleCapHit(Crawler $crawler, Run $run, int $blockFrom): void
    {
        $lastSuccessfulRun = $this->runs->lastCompletedRun(crawler: $crawler, excludeRunId: $run->id);

        $lastSuccessfulScanAt = $lastSuccessfulRun?->finished_at?->toIso8601String();

        $this->runs->flagCapHit(run: $run, possibleSkipWindow: [
            // Окно простоя — от последнего УСПЕШНОГО скана этого профиля (`02` §4.1), не от
            // старта текущего прогона (тот совпадает с моментом обнаружения простоя, а не с
            // моментом, когда профиль в последний раз реально досканил); нет предыдущего
            // completed-прогона (первый скан профиля) — fallback на старт текущего.
            'from' => $lastSuccessfulScanAt ?? $run->started_at?->toIso8601String(),
            'to' => now()->toIso8601String(),
        ]);

        Log::warning(
            message: 'FreshRunProcessor: dedup signature cap reached, possible skip window.',
            context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'blockFrom' => $blockFrom],
        );
    }
}
