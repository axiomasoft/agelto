<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\ListingExecutionContext;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Exceptions\Procurement\Crawler\ListingRequestStopped;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Models\Procurement\Crawler\Run;

final readonly class ListingRunCheckpoint
{
    public function __construct(private CrawlerRunManager $runs, private RunStopGate $stopGate) {}

    public function context(Run $run, float $deadlineAt): ListingExecutionContext
    {
        return new ListingExecutionContext($run, $deadlineAt, function () use ($run, $deadlineAt): void {
            // heartbeat asserts the execution token before any further request or observation.
            $this->runs->heartbeat(run: $run);
            $signal = $this->stopGate->signal(run: $run);

            if ($signal !== null) {
                $reason = "Остановлено оператором ({$signal->value}).";
                match ($signal) {
                    RunStopKind::Pause => $this->runs->pause(run: $run, reason: $reason),
                    RunStopKind::Cancel => $this->runs->cancel(run: $run, reason: $reason),
                };
                throw new ListingRequestStopped('Listing stopped by operator.');
            }

            if (microtime(true) >= $deadlineAt - 5) {
                throw OutboundRequestDeadlineExceeded::forStage('listing');
            }
        });
    }
}
