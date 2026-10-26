<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\Contracts\ProcessClock;

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
        return hrtime(true);
    }

    public function sleep(Duration $duration): void
    {
        usleep($duration->milliseconds * 1000);
    }
}
