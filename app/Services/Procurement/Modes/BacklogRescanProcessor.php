<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sweep-потребитель `cap_hit` (`02-crawling.md` §4.1, D22): единственный, кто
 * реагирует на флаг, поднятый `FreshRunProcessor` — точечный archive-рескан окна
 * простоя (`possible_skip_window`) на archive-профиле того же реестра, по дням
 * (как `ArchiveRunProcessor`), общим `ArchiveWindowScanner` по update-окну.
 */
final readonly class BacklogRescanProcessor
{
    public function __construct(
        private CrawlerRunManager $runs,
        private ArchiveWindowScanner $scanner,
    ) {}

    public function sweep(): int
    {
        $processed = 0;

        foreach ($this->pendingFreshRuns() as $freshRun) {
            if ($this->rescanOne(freshRun: $freshRun)) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * @return iterable<int, Run>
     */
    private function pendingFreshRuns(): iterable
    {
        return Run::query()
            ->where('status', RunStatus::Completed)
            ->where('stats->cap_hit', true)
            ->whereNull('stats->backlog_rescanned_at')
            ->with('crawler')
            ->cursor();
    }

    private function rescanOne(Run $freshRun): bool
    {
        $crawler = $freshRun->crawler;

        $archiveCrawler = Crawler::query()
            ->where('registry_id', $crawler->registry_id)
            ->where('mode', CrawlerMode::Archive)
            ->where('is_active', true)
            ->first();

        if ($archiveCrawler === null) {
            Log::warning(
                message: 'BacklogRescanProcessor: no active archive profile for registry, skipping.',
                context: ['fresh_run_id' => $freshRun->id, 'registry_id' => $crawler->registry_id],
            );

            return false;
        }

        $window = $freshRun->stats?->toArray()['possible_skip_window'] ?? [];
        $from = $window['from'] ?? null;
        $to = $window['to'] ?? null;

        if ($from === null || $to === null) {
            Log::warning(
                message: 'BacklogRescanProcessor: possible_skip_window has no resolvable "from", skipping.',
                context: ['fresh_run_id' => $freshRun->id],
            );

            return false;
        }

        $archiveRun = $this->runs->startOrResume(crawler: $archiveCrawler);

        try {
            $this->drainWindow(crawler: $archiveCrawler, run: $archiveRun, from: Carbon::parse($from), to: Carbon::parse($to));
        } catch (Throwable $exception) {
            $this->runs->fail(run: $archiveRun, error: $exception->getMessage());

            Log::warning(
                message: 'BacklogRescanProcessor: archive rescan failed, flag stays unmarked for the next sweep.',
                context: ['fresh_run_id' => $freshRun->id, 'archive_run_id' => $archiveRun->id, 'error' => $exception->getMessage()],
            );

            return false;
        }

        $this->runs->complete(run: $archiveRun);
        $this->runs->markBacklogRescanned(run: $freshRun);

        return true;
    }

    private function drainWindow(Crawler $crawler, Run $run, Carbon $from, Carbon $to): void
    {
        $cursor = ListingCursor::fromArray(cursor: $run->cursor?->toArray() ?? []);
        $day = $cursor->day !== null ? Carbon::parse($cursor->day) : $from->copy()->startOfDay();

        if ($cursor->day === null) {
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(blockFrom: 1, day: $day->toDateString()));
        }

        while ($day->lte($to)) {
            $this->scanner->scan(crawler: $crawler, run: $run, filter: new WindowFilter(
                dateField: 'update',
                dateFrom: $day->copy(),
                dateTo: $day->copy(),
            ));

            $day = $day->copy()->addDay();
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(blockFrom: 1, day: $day->toDateString()));
        }
    }
}
