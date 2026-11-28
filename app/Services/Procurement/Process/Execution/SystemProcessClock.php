<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Primitives\DbInstant;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Support\Time\Monotonic;

/** Production clock: `clock_timestamp()` PostgreSQL, hrtime и usleep. */
final readonly class SystemProcessClock implements ProcessClock
{
    public function dbNow(): DbInstant
    {
        $now = LedgerRow::one('SELECT clock_timestamp()::text AS now') ?? throw new \LogicException('Database clock returned no instant.');

        return new DbInstant(new \DateTimeImmutable($now->string('now')));
    }

    public function monotonicNs(): int
    {
        return Monotonic::nanoseconds();
    }

    public function sleep(Duration $duration): void
    {
        usleep($duration->milliseconds * 1000);
    }
}
