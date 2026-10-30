<?php

declare(strict_types=1);

namespace App\Services\Procurement\Modes;

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\Crawler\CrawlerRunBusyException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Network\LegacyAcquisitionControl;
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
        private LegacyAcquisitionControl $sourceControl,
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

        if (! $crawler instanceof Crawler) {
            Log::warning(
                message: 'BacklogRescanProcessor: fresh run has no crawler, skipping.',
                context: ['fresh_run_id' => $freshRun->id],
            );

            return false;
        }

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

        $stats = $freshRun->stats === null
            ? []
            : $this->stringKeyedArray(values: $freshRun->stats->toArray());
        $window = $stats['possible_skip_window'] ?? [];

        if (! is_array($window)) {
            $window = [];
        }

        $from = $window['from'] ?? null;
        $to = $window['to'] ?? null;

        if (! is_string($from) || ! is_string($to)) {
            Log::warning(
                message: 'BacklogRescanProcessor: possible_skip_window has no resolvable "from", skipping.',
                context: ['fresh_run_id' => $freshRun->id],
            );

            return false;
        }

        $freshCursor = ListingCursor::fromArray(cursor: $freshRun->cursor?->toArray() ?? []);

        if ($freshCursor->recoveryScope === null) {
            Log::warning(
                message: 'BacklogRescanProcessor: fresh run has no persisted recovery scope, skipping.',
                context: ['fresh_run_id' => $freshRun->id, 'crawler_id' => $crawler->id],
            );

            return false;
        }

        $recoveryScope = RecoveryScope::fromPersistedFreshCrawler(
            crawler: $crawler,
            payload: $freshCursor->recoveryScope,
        );

        try {
            $archiveRun = $this->runs->startOrResume(
                crawler: $archiveCrawler,
                purpose: RunPurpose::Backlog,
                subjectKey: "fresh-run:{$freshRun->id}",
            );
        } catch (CrawlerRunBusyException) {
            return false;
        }

        if (! $this->runs->acquireExecutionLease(run: $archiveRun)) {
            return false;
        }

        try {
            $result = $this->drainWindow(
                crawler: $archiveCrawler,
                run: $archiveRun,
                from: Carbon::parse($from),
                to: Carbon::parse($to),
                recoveryScope: $recoveryScope,
            );

            if ($result !== ArchiveWindowScanResult::Drained) {
                return false;
            }

            // Кооперативный стоп мог оборвать drainWindow() между днями (P3.7, D111): архивный
            // прогон тогда носит Paused/Cancelled, а не Running — рескан не считается завершённым,
            // fresh-прогон НЕ помечается rescanned, иначе потерянное окно станет невидимым навсегда.
            if ($archiveRun->refresh()->status !== RunStatus::Running) {
                return false;
            }

            if (! $this->runs->complete(run: $archiveRun)) {
                return false;
            }

            $this->runs->markBacklogRescanned(run: $freshRun);

            return true;
        } catch (Throwable $exception) {
            // Последняя страховка: пауза с тем же курсором, не провал (см. `LegacyAcquisitionControl::interrupted`).
            $this->sourceControl->interrupted(run: $archiveRun, exception: $exception);

            Log::warning(
                message: 'BacklogRescanProcessor: archive rescan failed, flag stays unmarked for the next sweep.',
                context: ['fresh_run_id' => $freshRun->id, 'archive_run_id' => $archiveRun->id, 'error' => $exception->getMessage()],
            );

            return false;
        } finally {
            $this->runs->releaseExecutionLease(run: $archiveRun);
        }
    }

    private function drainWindow(Crawler $crawler, Run $run, Carbon $from, Carbon $to, RecoveryScope $recoveryScope): ArchiveWindowScanResult
    {
        $overlapDays = config('crawler.tracking.window_overlap_days');

        if (! is_int($overlapDays)) {
            throw new \UnexpectedValueException('crawler.tracking.window_overlap_days must be an integer.');
        }

        $overlappedFrom = $from->copy()->subDays($overlapDays)->startOfDay();

        $cursorAttributes = $run->cursor === null
            ? []
            : $this->stringKeyedArray(values: $run->cursor->toArray());
        $cursor = ListingCursor::fromArray(cursor: $cursorAttributes);
        $recoveryScope = $cursor->recoveryScope === null
            ? $recoveryScope
            : RecoveryScope::fromArray($cursor->recoveryScope);
        $day = $cursor->day !== null ? Carbon::parse($cursor->day) : $overlappedFrom->copy();

        if ($cursor->day === null) {
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(
                blockFrom: 1,
                day: $day->toDateString(),
                recoveryScope: $recoveryScope->toArray(),
            ));
        }

        Log::info(
            message: 'BacklogRescanProcessor: перекрытое окно рескана.',
            context: [
                'crawler_id' => $crawler->id,
                'run_id' => $run->id,
                'requested_from' => $from->toDateString(),
                'overlap_days' => $overlapDays,
                'from' => $overlappedFrom->toDateString(),
                'to' => $to->toDateString(),
            ],
        );

        while ($day->lte($to)) {
            $run->refresh();

            if ($run->status !== RunStatus::Running) {
                return ArchiveWindowScanResult::Stopped;
            }

            $result = $this->scanner->scan(crawler: $crawler, run: $run, filter: new WindowFilter(
                dateField: 'update',
                dateFrom: $day->copy(),
                dateTo: $day->copy(),
            ), recoveryScope: $recoveryScope);

            if ($result !== ArchiveWindowScanResult::Drained) {
                return $result;
            }

            $day = $day->copy()->addDay();
            $this->runs->updateCursor(run: $run, cursor: new ListingCursor(
                blockFrom: 1,
                day: $day->toDateString(),
                recoveryScope: $recoveryScope->toArray(),
            ));
        }

        return ArchiveWindowScanResult::Drained;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
