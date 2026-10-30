<?php

declare(strict_types=1);

namespace App\Services\Procurement\Crawler;

use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Process\StageCode;
use App\Jobs\Procurement\CrawlRunJob;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Network\AcquisitionEligibility;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;

/** Existing profile-run continuation only; no health mutation or new business retry authority. */
final readonly class ResumeWaitingRuns
{
    public function __construct(private CrawlerRunManager $runs, private AcquisitionEligibility $eligibility) {}

    public function resumeDue(int $limit = 100): int
    {
        $count = 0;
        $excluded = $this->eligibility->excludedPlatforms(StageCode::Listing);
        foreach ($this->runs->waitingProfileRuns($excluded, $limit) as $candidate) {
            $run = $this->runs->claimWaitingProfileRun($candidate);
            if ($run === null) {
                continue;
            }
            $context = $run->stats?->get('execution_context');
            if (! is_array($context)) {
                continue;
            }
            $mode = is_string($context['mode'] ?? null) ? CrawlerMode::tryFrom($context['mode']) : null;
            $lane = is_string($context['execution_lane'] ?? null) ? CrawlerExecutionLane::tryFrom($context['execution_lane']) : null;
            $from = $context['from'] ?? null;
            $to = $context['to'] ?? null;
            if ($mode === null || $lane === null || ($from !== null && ! is_string($from)) || ($to !== null && ! is_string($to))
                || ($mode === CrawlerMode::Archive && ($from === null || $to === null))) {
                continue;
            }
            $job = new CrawlRunJob(crawlerId: $run->crawler_id, mode: $mode, executionLane: $lane, from: $from, to: $to);
            $lock = app(UniqueLock::class);
            try {
                if (! $lock->acquire($job)) {
                    continue;
                }
                try {
                    Bus::dispatch($job);
                } catch (\Throwable $failure) {
                    $lock->release($job);
                    throw $failure;
                }
            } catch (\Throwable $failure) {
                $this->runs->releaseWaitingProfileClaim($run);
                throw $failure;
            }
            $count++;
        }

        return $count;
    }
}
