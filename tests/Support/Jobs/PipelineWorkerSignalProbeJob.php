<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** Short-lived real-worker probe used only by docker/tests/pipeline-worker-recovery-contract.sh. */
final class PipelineWorkerSignalProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $cacheKey,
        public readonly int $workSeconds,
    ) {}

    public function handle(): void
    {
        $activeKey = "{$this->cacheKey}:active";

        if (! Cache::add($activeKey, (string) getmypid(), now()->addMinute())) {
            Cache::put($this->cacheKey, 'overlap', now()->addMinute());

            return;
        }

        Cache::put($this->cacheKey, 'started:'.getmypid(), now()->addMinute());

        try {
            sleep($this->workSeconds);

            Cache::put($this->cacheKey, 'finished:'.getmypid(), now()->addMinute());
        } finally {
            Cache::forget($activeKey);
        }
    }
}
