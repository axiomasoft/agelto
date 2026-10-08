<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\Contracts\HasPriceRange;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\Contracts\DeadlineAwareSourceMethod;
use App\Services\Procurement\SourceControl\SourceRunControl;
use Illuminate\Support\Facades\Log;

/**
 * Дренаж одного date-окна с рекурсивным ценовым дроблением при «тихом cap»
 * (`02-crawling.md` §3.3/§4.2, D20/D21) — агностик, переиспользуется P4.1 (по дням
 * публикации) и P4.2 (по окну обновления). Переполнение детектируется БЕЗ счётчика
 * записей: окно дренировано при `rowsTotal < block_size`, cap — при повторе
 * `dedupSignature` на полном блоке.
 *
 * Стоп-гейт (P3.2, D28) читается в той же точке, что уже делает `$crawler->refresh()`,
 * и останавливает дренаж ТЕКУЩЕГО окна с уже сохранённым курсором. Дневные обёртки
 * (`ArchiveRunProcessor`/`DeltaScanProcessor`/`BacklogRescanProcessor`) после `scan()`
 * перечитывают статус прогона (P3.7), поэтому pause/cancel останавливает и сам прогон.
 * Archive исполняется ограниченными по времени slice: после каждого атомарно сохранённого
 * блока возвращает Checkpointed, чтобы CrawlRunJob поставил durable continuation.
 */
final readonly class ArchiveWindowScanner
{
    public function __construct(
        private SourceMethodFactory $factory,
        private CrawlerRunManager $runs,
        private CsvBlockIngestor $ingestor,
        private RunStopGate $stopGate,
        private SourceRunControl $sourceControl,
    ) {}

    public function scan(Crawler $crawler, Run $run, WindowFilter $filter, ?RecoveryScope $recoveryScope = null, ?float $deadlineAt = null): ArchiveWindowScanResult
    {
        $startedAt = microtime(true);
        $checkpointSeconds = self::nonNegativeIntConfig('crawler.run.checkpoint_seconds');
        $deadlineAt = min($deadlineAt ?? INF, $startedAt + $checkpointSeconds);
        $blockSize = app(PlatformAdapterRegistry::class)->blockSize(registry: $crawler->registry()->firstOrFail());
        $priceCeiling = self::positiveIntConfig('crawler.archive.price_ceiling');
        $minPriceInterval = self::nonNegativeIntConfig('crawler.archive.min_price_interval');
        $maxBlocksPerWindow = self::nonNegativeIntConfig('crawler.archive.max_blocks_per_window');

        $cursor = ListingCursor::fromArray(cursor: self::cursorPayload($run->cursor?->toArray()));
        $recoveryScope ??= $cursor->recoveryScope === null
            ? null
            : RecoveryScope::fromArray($cursor->recoveryScope);
        $day = $cursor->day ?? $filter->dateFrom->toDateString();
        $dateField = $filter->dateField;

        $executionCrawler = $recoveryScope?->applyTo(runtimeCrawler: $crawler) ?? $crawler;
        $profileFilter = $executionCrawler->filter;
        $startingInterval = $profileFilter instanceof HasPriceRange
            ? $profileFilter->startingPriceInterval()
            : null;
        $stack = $cursor->priceStack ?? [$startingInterval ?? "0:{$priceCeiling}"];
        $blockFrom = $cursor->priceStack === null ? 1 : $cursor->blockFrom;
        $previousSignature = $cursor->lastDedupSignature;
        $blocksInInterval = $cursor->blocksInInterval;
        if ($run->stats?->get('source_suspended') === true && $blockFrom > 1) {
            // Offset replay cannot recover deletions/reordering before the cursor. Restart the
            // bounded date/price interval; idempotent ingest absorbs already committed rows.
            $blockFrom = 1;
            $previousSignature = null;
            $blocksInInterval = 0;
            app(WindowStability::class)->restartInterruptedPass(run: $run);
        }

        if ($cursor->recoveryScope === null && $recoveryScope !== null) {
            $this->persistCursor(
                run: $run,
                day: $day,
                stack: $stack,
                blockFrom: $blockFrom,
                dateField: $dateField,
                lastDedupSignature: $previousSignature,
                blocksInInterval: $blocksInInterval,
                recoveryScope: $recoveryScope,
            );
        }

        while ($stack !== []) {
            $crawler->refresh();

            // Lease is renewed at the narrow boundary before the externally visible scanner
            // section. A process that lost ownership never starts another listing request.
            $this->runs->heartbeat(run: $run);

            $stopSignal = $this->stopGate->signal(run: $run);

            if ($stopSignal !== null) {
                $reason = "Остановлено оператором ({$stopSignal->value}).";
                match ($stopSignal) {
                    RunStopKind::Pause => $this->runs->pause(run: $run, reason: $reason),
                    RunStopKind::Cancel => $this->runs->cancel(run: $run, reason: $reason),
                };

                return ArchiveWindowScanResult::Stopped;
            }

            if (microtime(true) >= $deadlineAt) {
                $this->runs->recordCheckpoint(run: $run);

                return ArchiveWindowScanResult::Checkpointed;
            }

            if ($blocksInInterval >= $maxBlocksPerWindow) {
                Log::warning(
                    message: 'ArchiveWindowScanner: max blocks per price interval reached; interval remains operator debt.',
                    context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'day' => $day, 'interval' => $stack[array_key_last($stack)]],
                );
                $this->persistCursor(
                    run: $run,
                    day: $day,
                    stack: $stack,
                    blockFrom: $blockFrom,
                    dateField: $dateField,
                    lastDedupSignature: $previousSignature,
                    blocksInInterval: $blocksInInterval,
                    recoveryScope: $recoveryScope,
                );

                return ArchiveWindowScanResult::OperatorDebt;
            }

            $iterationCursor = new ListingCursor(
                blockFrom: $blockFrom,
                day: $day,
                priceStack: $stack,
                dateField: $dateField,
                lastDedupSignature: $previousSignature,
                blocksInInterval: $blocksInInterval,
            );
            $executionCrawler = clone $crawler;
            $executionCrawler->filter = $profileFilter;
            $executionCrawler = $recoveryScope?->applyTo(runtimeCrawler: $executionCrawler) ?? $executionCrawler;
            // Источник на паузе (брейкер открыт или пауза оператора): прогон приостановлен с тем
            // же курсором; его поднимет `crawler:source-circuits` или оператор.
            if (! $this->sourceControl->admit(crawler: $crawler, run: $run)) {
                return ArchiveWindowScanResult::Stopped;
            }

            $executionCrawler->load('registry.platform');
            $sourceMethod = $this->factory->makeWithFallback(crawler: $executionCrawler);
            try {
                $batch = $sourceMethod instanceof DeadlineAwareSourceMethod
                    ? $sourceMethod->collectListingWithinDeadline(crawler: $executionCrawler, cursor: $iterationCursor, deadlineAt: $deadlineAt)
                    : $sourceMethod->collectListing(crawler: $executionCrawler, cursor: $iterationCursor);
            } catch (PlatformBudgetExhaustedException|OutboundRequestDeadlineExceeded) {
                $this->runs->recordCheckpoint(run: $run);

                return ArchiveWindowScanResult::Checkpointed;
            } catch (\Throwable $exception) {
                $this->sourceControl->onBlockException(crawler: $crawler, run: $run, exception: $exception, requestParams: $iterationCursor->toArray());

                return ArchiveWindowScanResult::Stopped;
            }

            $fetchResult = null;
            $blockException = null;

            try {
                // A request may outlive the lease. Its ingestion and checkpoint are one short
                // PostgreSQL row-locked section: a takeover waits for all block writes to commit
                // or roll back, and a former owner cannot persist a partial response afterwards.
                $artifactRef = $this->ingestor->stageArtifact(crawler: $executionCrawler, run: $run, batch: $batch);
                $result = $this->runs->withinExecutionLease(run: $run, callback: function () use (
                    $artifactRef,
                    $executionCrawler,
                    &$blockFrom,
                    &$blocksInInterval,
                    &$previousSignature,
                    &$stack,
                    &$fetchResult,
                    $batch,
                    $blockSize,
                    $crawler,
                    $dateField,
                    $day,
                    $minPriceInterval,
                    $recoveryScope,
                    $run,
                ): ?ArchiveWindowScanResult {
                    $fetchResult = $this->ingestor->ingest(crawler: $executionCrawler, run: $run, batch: $batch, artifactRef: $artifactRef);

                    if ($fetchResult->failure !== null) {
                        // Любая непрочитанная строка оставляет курсор на текущем блоке.
                        // Инцидент и пауза записываются после транзакции.
                        return null;
                    }

                    $terminal = $batch->rowsTotal < $blockSize;
                    if ($terminal) {
                        // Persist the next complete pass before observe may release the lease
                        // and pause. Resuming a held window must never hash only its last page.
                        $this->persistCursor(
                            run: $run, day: $day, stack: $stack, blockFrom: 1,
                            dateField: $dateField, lastDedupSignature: null,
                            blocksInInterval: 0, recoveryScope: $recoveryScope,
                        );
                    }
                    $stable = app(WindowStability::class)->observe(
                        run: $run,
                        window: $dateField.':'.$day.':'.($stack[array_key_last($stack) ?? 0] ?? throw new \LogicException('Empty scan interval.')),
                        batch: $batch,
                        terminal: $terminal,
                    );
                    if ($run->status !== RunStatus::Running) {
                        return ArchiveWindowScanResult::Stopped;
                    }
                    if ($terminal) {
                        if ($stable) {
                            array_pop($stack);
                        }
                        $blockFrom = 1;
                        $previousSignature = null;
                        $blocksInInterval = 0;
                    } elseif ($previousSignature !== null && $batch->dedupSignature === $previousSignature) {
                        $splitStack = $this->splitTopInterval(stack: $stack, minPriceInterval: $minPriceInterval, crawler: $crawler, run: $run, day: $day);

                        if ($splitStack === null) {
                            $this->persistCursor(
                                run: $run,
                                day: $day,
                                stack: $stack,
                                blockFrom: $blockFrom,
                                dateField: $dateField,
                                lastDedupSignature: $previousSignature,
                                blocksInInterval: $blocksInInterval,
                                recoveryScope: $recoveryScope,
                            );

                            return ArchiveWindowScanResult::OperatorDebt;
                        }

                        $stack = $splitStack;
                        $blockFrom = 1;
                        $previousSignature = null;
                        $blocksInInterval = 0;
                    } else {
                        $previousSignature = $batch->dedupSignature;
                        $blockFrom += $blockSize;
                        $blocksInInterval++;
                    }

                    $this->persistCursor(
                        run: $run,
                        day: $day,
                        stack: $stack,
                        blockFrom: $blockFrom,
                        dateField: $dateField,
                        lastDedupSignature: $previousSignature,
                        blocksInInterval: $blocksInInterval,
                        recoveryScope: $recoveryScope,
                    );
                    $this->runs->heartbeat(run: $run);

                    return null;
                });
            } catch (\Throwable $exception) {
                $blockException = $exception;
            }

            if ($blockException !== null) {
                // Транзакция блока откатилась; журналируем отказ с исходной позицией.
                $run->refresh();
                $this->sourceControl->onBlockException(crawler: $crawler, run: $run, exception: $blockException, requestParams: $iterationCursor->toArray());

                return ArchiveWindowScanResult::Stopped;
            }

            // Вне транзакции блока: инцидент и брейкер сохраняются, даже если блок откатился бы.
            if ($fetchResult !== null && $this->sourceControl->afterBlock(crawler: $crawler, run: $run, result: $fetchResult, requestParams: $iterationCursor->toArray())) {
                return ArchiveWindowScanResult::Stopped;
            }

            if ($result !== null) {
                return $result;
            }
        }

        return ArchiveWindowScanResult::Drained;
    }

    /**
     * @param  list<string>  $stack
     * @return list<string>|null
     */
    private function splitTopInterval(array $stack, int $minPriceInterval, Crawler $crawler, Run $run, string $day): ?array
    {
        $top = array_pop($stack);

        if (! is_string($top)) {
            throw new \LogicException('Ценовой стек не может быть пустым.');
        }

        [$lo, $hi] = array_map(intval(...), explode(':', $top));

        if ($hi - $lo <= $minPriceInterval) {
            Log::warning(
                message: 'ArchiveWindowScanner: price interval at minimum; remainder remains operator debt.',
                context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'day' => $day, 'interval' => $top],
            );

            return null;
        }

        $mid = $lo + intdiv($hi - $lo, 2);

        // Толкаем в порядке «сначала обрабатывается нижний» — верхняя половина уходит
        // глубже в стек, нижняя оказывается на вершине и обрабатывается следующей.
        $stack[] = "{$mid}:{$hi}";
        $stack[] = "{$lo}:{$mid}";

        return $stack;
    }

    /**
     * @param  list<string>  $stack
     * @param  'publish'|'update'  $dateField
     */
    private function persistCursor(
        Run $run,
        string $day,
        array $stack,
        int $blockFrom,
        string $dateField,
        ?string $lastDedupSignature,
        int $blocksInInterval,
        ?RecoveryScope $recoveryScope = null,
    ): void {
        $this->runs->updateCursor(run: $run, cursor: new ListingCursor(
            blockFrom: $blockFrom,
            day: $day,
            priceStack: $stack === [] ? null : $stack,
            dateField: $dateField,
            lastDedupSignature: $lastDedupSignature,
            blocksInInterval: $blocksInInterval,
            recoveryScope: $recoveryScope?->toArray(),
        ));
    }

    /** @return array<string, mixed> */
    private static function cursorPayload(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new \LogicException('Курсор запуска должен быть массивом.');
        }

        $payload = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new \LogicException('Курсор запуска должен иметь строковые ключи.');
            }

            $payload[$key] = $entry;
        }

        return $payload;
    }

    private static function positiveIntConfig(string $key, ?int $default = null): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new \LogicException("{$key} должен быть положительным целым числом.");
        }

        return $value;
    }

    private static function nonNegativeIntConfig(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value < 0) {
            throw new \LogicException("{$key} должен быть неотрицательным целым числом.");
        }

        return $value;
    }
}
