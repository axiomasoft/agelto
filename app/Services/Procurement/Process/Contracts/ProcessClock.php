<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Services\Procurement\Process\SystemProcessClock;
use Illuminate\Container\Attributes\Bind;

/**
 * Часы исполнения: DB clock — authority lease/due; monotonic — локальные slice/heartbeat deadlines,
 * устойчивые к сдвигу PHP wall clock. sleep — единственная точка ожидания, tests её ускоряют.
 */
#[Bind(SystemProcessClock::class)]
interface ProcessClock
{
    public function dbNow(): DbInstant;

    public function monotonicNs(): int;

    public function sleep(Duration $duration): void;
}
