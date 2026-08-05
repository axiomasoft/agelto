<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\CsvBlockIngestor;
use App\Services\Procurement\PoliteThrottle;
use App\Services\Procurement\SourceMethodFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Оркестрация fresh-прогона (`02-crawling.md` §4.1): цикл блоков листинга до первого
 * известного блока или cap по повтору `dedupSignature`, с heartbeat/курсором на каждом
 * шаге и safety hard-cap от бесконечного цикла.
 */
final readonly class FreshRunProcessor
{
    public function __construct(
        private SourceMethodFactory $factory,
        private CrawlerRunManager $runs,
        private PoliteThrottle $throttle,
        private CsvBlockIngestor $ingestor,
    ) {}

    public function run(Crawler $crawler): Run
    {
        $run = $this->runs->startOrResume(crawler: $crawler);
        $this->runs->recordScanGap(
            run: $run,
            previousCompletedRun: $this->runs->lastCompletedRun(crawler: $crawler, excludeRunId: $run->id),
        );

        $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);
        $blockSize = (int) config('crawler.eis.block_size', 500);
        $maxBlocks = (int) config('crawler.fresh_max_blocks', 40);
        $previousSignature = null;

        try {
            for ($iteration = 0; $iteration < $maxBlocks; $iteration++) {
                $crawler->refresh();
                $sourceMethod = $this->factory->makeWithFallback(crawler: $crawler);
                $batch = $sourceMethod->collectListing(crawler: $crawler, cursor: $cursor);
                $fetchResult = $this->ingestor->ingest(crawler: $crawler, run: $run, batch: $batch);

                if ($fetchResult->oldestUpdateSeen !== null) {
                    $this->runs->recordOldestUpdateSeen(run: $run, candidate: $fetchResult->oldestUpdateSeen);
                }

                if ($fetchResult->rowsNew === 0 && $fetchResult->rowsUpdated === 0) {
                    $cursor = new ListingCursor(
                        blockFrom: $cursor->blockFrom,
                        day: $cursor->day,
                        priceStack: $cursor->priceStack,
                        reachedKnown: true,
                    );
                    $this->runs->updateCursor(run: $run, cursor: $cursor);
                    $this->runs->complete(run: $run);

                    return $run;
                }

                if ($previousSignature !== null && $batch->dedupSignature === $previousSignature) {
                    $this->handleCapHit(crawler: $crawler, run: $run, blockFrom: $cursor->blockFrom);

                    $cursor = new ListingCursor(
                        blockFrom: $cursor->blockFrom,
                        day: $cursor->day,
                        priceStack: $cursor->priceStack,
                        reachedKnown: true,
                    );
                    $this->runs->updateCursor(run: $run, cursor: $cursor);
                    $this->runs->complete(run: $run);

                    return $run;
                }

                $previousSignature = $batch->dedupSignature;

                $cursor = new ListingCursor(
                    blockFrom: $cursor->blockFrom + $blockSize,
                    day: $cursor->day,
                    priceStack: $cursor->priceStack,
                    reachedKnown: false,
                );
                $this->runs->updateCursor(run: $run, cursor: $cursor);
                $this->runs->heartbeat(run: $run);
                $this->throttle->wait();
            }
        } catch (Throwable $exception) {
            $this->runs->fail(run: $run, error: $exception->getMessage());

            throw $exception;
        }

        Log::warning(
            message: 'FreshRunProcessor: hard cap reached without stop condition.',
            context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'max_blocks' => $maxBlocks],
        );
        $this->runs->complete(run: $run);

        return $run;
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
