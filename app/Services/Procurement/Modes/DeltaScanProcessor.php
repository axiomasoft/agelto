<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Плановый delta-scan (`research/05-change-tracking.md` §2.2/§3.1, П-3): суточный дренаж окна
 * `[сегодня − window_overlap_days; сегодня]` по дате обновления, на активном archive-профиле
 * реестра — своей механики обхода не заводит, переиспользует `ArchiveWindowScanner` тем же
 * способом, что и `BacklogRescanProcessor` (D33). Профиль резолвится тем же способом, что
 * `BacklogRescanProcessor::rescanOne()` (`:62-67`) — активный archive-профиль реестра.
 */
final readonly class DeltaScanProcessor
{
    public function __construct(
        private CrawlerRunManager $runs,
        private ArchiveWindowScanner $scanner,
    ) {}

    public function run(Crawler $crawler): ?Run
    {
        $archiveCrawler = Crawler::query()
            ->where('registry_id', $crawler->registry_id)
            ->where('mode', CrawlerMode::Archive)
            ->where('is_active', true)
            ->first();

        if ($archiveCrawler === null) {
            Log::warning(
                message: 'DeltaScanProcessor: no active archive profile for registry, skipping.',
                context: ['crawler_id' => $crawler->id, 'registry_id' => $crawler->registry_id],
            );

            return null;
        }

        $overlapDays = (int) config('crawler.tracking.window_overlap_days');
        $to = Carbon::today();
        $from = $to->copy()->subDays($overlapDays);

        $run = $this->runs->startOrResume(crawler: $archiveCrawler);

        // День — то же резюмируемое устройство, что `BacklogRescanProcessor::drainWindow()`:
        // `ArchiveWindowScanner::scan()` сам по себе дренирует РОВНО один день (курсор — источник
        // истины про текущий день, `WindowFilter->dateTo` внутри сканера не читается); суточная
        // итерация окна — оркестрация вызывающего кода, не механика обхода (D33).
        $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);
        $day = $cursor->day !== null ? Carbon::parse($cursor->day) : $from->copy();

        if ($cursor->day === null) {
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(blockFrom: 1, day: $day->toDateString()));
        }

        while ($day->lte($to)) {
            $run->refresh();

            if ($run->status !== RunStatus::Running) {
                return $run;
            }

            $result = $this->scanner->scan(crawler: $archiveCrawler, run: $run, filter: new WindowFilter(
                dateField: 'update',
                dateFrom: $day->copy(),
                dateTo: $day->copy(),
            ));

            if ($result !== ArchiveWindowScanResult::Drained) {
                return $run;
            }

            $day = $day->copy()->addDay();
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(blockFrom: 1, day: $day->toDateString()));
        }

        $this->runs->complete(run: $run);

        return $run;
    }
}
