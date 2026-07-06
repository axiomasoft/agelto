<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Dto\Crawler\ListingCursor;
use App\Dto\Crawler\WindowFilter;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\Run;
use Illuminate\Support\Facades\Log;

/**
 * Дренаж одного date-окна с рекурсивным ценовым дроблением при «тихом cap»
 * (`02-crawling.md` §3.3/§4.2, D20/D21) — агностик, переиспользуется P4.1 (по дням
 * публикации) и P4.2 (по окну обновления). Переполнение детектируется БЕЗ счётчика
 * записей: окно дренировано при `rowsTotal < block_size`, cap — при повторе
 * `dedupSignature` на полном блоке.
 */
final readonly class ArchiveWindowScanner
{
    public function __construct(
        private SourceMethodFactory $factory,
        private CrawlerRunManager $runs,
        private PoliteThrottle $throttle,
        private CsvBlockIngestor $ingestor,
    ) {}

    public function scan(Crawler $crawler, Run $run, WindowFilter $filter): void
    {
        $blockSize = (int) config('crawler.eis.block_size', 500);
        $priceCeiling = (int) config('crawler.archive.price_ceiling');
        $minPriceInterval = (int) config('crawler.archive.min_price_interval');
        $maxBlocksPerWindow = (int) config('crawler.archive.max_blocks_per_window');

        $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);
        $day = $cursor->day ?? $filter->dateFrom->toDateString();
        $dateField = $filter->dateField;
        $stack = $cursor->priceStack ?? ["0:{$priceCeiling}"];
        $blockFrom = $cursor->priceStack === null ? 1 : $cursor->blockFrom;
        $previousSignature = null;
        $blocksInInterval = 0;

        while ($stack !== []) {
            if ($blocksInInterval >= $maxBlocksPerWindow) {
                Log::warning(
                    message: 'ArchiveWindowScanner: max blocks per price interval reached, forcing split point.',
                    context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'day' => $day, 'interval' => $stack[array_key_last($stack)]],
                );
                array_pop($stack);
                $blockFrom = 1;
                $previousSignature = null;
                $blocksInInterval = 0;
                $this->persistCursor(run: $run, day: $day, stack: $stack, blockFrom: $blockFrom, dateField: $dateField);

                continue;
            }

            $iterationCursor = new ListingCursor(blockFrom: $blockFrom, day: $day, priceStack: $stack, dateField: $dateField);
            $sourceMethod = $this->factory->makeWithFallback(crawler: $crawler);
            $batch = $sourceMethod->collectListing(crawler: $crawler, cursor: $iterationCursor);
            $this->ingestor->ingest(crawler: $crawler, run: $run, batch: $batch);

            if ($batch->rowsTotal < $blockSize) {
                array_pop($stack);
                $blockFrom = 1;
                $previousSignature = null;
                $blocksInInterval = 0;
            } elseif ($previousSignature !== null && $batch->dedupSignature === $previousSignature) {
                $stack = $this->splitTopInterval(stack: $stack, minPriceInterval: $minPriceInterval, crawler: $crawler, run: $run, day: $day);
                $blockFrom = 1;
                $previousSignature = null;
                $blocksInInterval = 0;
            } else {
                $previousSignature = $batch->dedupSignature;
                $blockFrom += $blockSize;
                $blocksInInterval++;
            }

            $this->persistCursor(run: $run, day: $day, stack: $stack, blockFrom: $blockFrom, dateField: $dateField);
            $this->runs->heartbeat(run: $run);
            $this->throttle->wait();
        }
    }

    /**
     * @param  list<string>  $stack
     * @return list<string>
     */
    private function splitTopInterval(array $stack, int $minPriceInterval, Crawler $crawler, Run $run, string $day): array
    {
        $top = $stack[array_key_last($stack)];
        [$lo, $hi] = array_map(intval(...), explode(':', $top));
        array_pop($stack);

        if ($hi - $lo <= $minPriceInterval) {
            Log::warning(
                message: 'ArchiveWindowScanner: price interval at minimum, dropping remainder (dataset tolerance).',
                context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'day' => $day, 'interval' => $top],
            );

            return $stack;
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
    private function persistCursor(Run $run, string $day, array $stack, int $blockFrom, string $dateField): void
    {
        $this->runs->updateCursor(run: $run, cursor: new ListingCursor(
            blockFrom: $blockFrom,
            day: $day,
            priceStack: $stack === [] ? null : $stack,
            dateField: $dateField,
        ));
    }
}
