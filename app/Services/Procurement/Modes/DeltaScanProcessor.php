<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\DeltaScanResult;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\Crawler\CrawlerRunBusyException;
use App\Models\Procurement\Crawler\Crawler;
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

    public function run(Crawler $crawler): DeltaScanResult
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

            return DeltaScanResult::missingArchiveProfile();
        }

        $overlapDays = config('crawler.tracking.window_overlap_days');

        if (! is_int($overlapDays)) {
            throw new \UnexpectedValueException('crawler.tracking.window_overlap_days must be an integer.');
        }
        $to = Carbon::today();
        $from = $to->copy()->subDays($overlapDays);

        try {
            $run = $this->runs->startOrResume(
                crawler: $archiveCrawler,
                purpose: RunPurpose::Delta,
                subjectKey: "fresh-crawler:{$crawler->id}",
            );
        } catch (CrawlerRunBusyException) {
            return DeltaScanResult::busy();
        }

        if (! $this->runs->acquireExecutionLease(run: $run)) {
            return DeltaScanResult::busy();
        }

        try {
            // День — то же резюмируемое устройство, что `BacklogRescanProcessor::drainWindow()`:
            // `ArchiveWindowScanner::scan()` сам по себе дренирует РОВНО один день (курсор — источник
            // истины про текущий день, `WindowFilter->dateTo` внутри сканера не читается); суточная
            // итерация окна — оркестрация вызывающего кода, не механика обхода (D33).
            $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);
            $recoveryScope = $cursor->recoveryScope === null
                ? RecoveryScope::fromFreshCrawler($crawler)
                : RecoveryScope::fromArray($cursor->recoveryScope);
            $day = $cursor->day !== null ? Carbon::parse($cursor->day) : $from->copy();

            if ($cursor->day === null) {
                $this->runs->updateCursor(run: $run, cursor: new ListingCursor(
                    blockFrom: 1,
                    day: $day->toDateString(),
                    recoveryScope: $recoveryScope->toArray(),
                ));
            }

            while ($day->lte($to)) {
                $run->refresh();

                if ($run->status !== RunStatus::Running) {
                    return DeltaScanResult::processed(run: $run);
                }

                $result = $this->scanner->scan(crawler: $archiveCrawler, run: $run, filter: new WindowFilter(
                    dateField: 'update',
                    dateFrom: $day->copy(),
                    dateTo: $day->copy(),
                ), recoveryScope: $recoveryScope);

                if ($result !== ArchiveWindowScanResult::Drained) {
                    return DeltaScanResult::processed(run: $run);
                }

                $day = $day->copy()->addDay();
                $this->runs->updateCursor(run: $run, cursor: new ListingCursor(
                    blockFrom: 1,
                    day: $day->toDateString(),
                    recoveryScope: $recoveryScope->toArray(),
                ));
            }

            if (! $this->runs->complete(run: $run)) {
                return DeltaScanResult::processed(run: $run);
            }

            return DeltaScanResult::processed(run: $run);
        } finally {
            $this->runs->releaseExecutionLease(run: $run);
        }
    }
}
