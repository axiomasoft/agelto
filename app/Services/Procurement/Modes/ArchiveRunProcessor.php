<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\CrawlerRunManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Оркестрация archive-прогона (`02-crawling.md` §4.2): по дням возрастающей даты
 * `[from, to]`, дренаж каждого дня — общий `ArchiveWindowScanner` (publish-окно =
 * один день); курсор `day` двигается только после полного дренажа дня, возобновление
 * оборванного прогона продолжается с последнего незавершённого дня.
 */
final readonly class ArchiveRunProcessor
{
    public function __construct(
        private CrawlerRunManager $runs,
        private ArchiveWindowScanner $scanner,
    ) {}

    public function run(Crawler $crawler, CarbonInterface $from, CarbonInterface $to): Run
    {
        $run = $this->runs->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);
        $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);
        $day = $cursor->day !== null ? Carbon::parse($cursor->day) : $from->copy()->startOfDay();

        if ($cursor->day === null) {
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(blockFrom: 1, day: $day->toDateString()));
        }

        try {
            while ($day->lte($to)) {
                $run->refresh();

                if ($run->status !== RunStatus::Running) {
                    return $run;
                }

                $result = $this->scanner->scan(crawler: $crawler, run: $run, filter: new WindowFilter(
                    dateField: 'publish',
                    dateFrom: $day->copy(),
                    dateTo: $day->copy(),
                ));

                if ($result !== ArchiveWindowScanResult::Drained) {
                    return $run;
                }

                $day = $day->copy()->addDay();
                $this->runs->updateCursor(run: $run, cursor: new ListingCursor(blockFrom: 1, day: $day->toDateString()));
            }
        } catch (Throwable $exception) {
            $this->runs->fail(run: $run, error: $exception->getMessage());

            throw $exception;
        }

        $this->runs->complete(run: $run);

        return $run;
    }
}
