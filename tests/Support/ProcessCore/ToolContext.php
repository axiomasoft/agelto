<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\CheckpointResult;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\LeaseRenewal;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;

final class ToolContext
{
    public static function make(bool $lose = false): ExecutionContext
    {
        $clock = new class implements ProcessClock
        {
            public function dbNow(): DbInstant
            {
                return new DbInstant(new \DateTimeImmutable);
            }

            public function monotonicNs(): int
            {
                return hrtime(true);
            }

            public function sleep(Duration $duration): void
            {
                usleep($duration->milliseconds * 1000);
            }
        };
        $keeper = new class($lose) implements LeaseKeeper
        {
            public function __construct(private bool $lose) {}

            public function heartbeat(ExecutionLease $lease): LeaseRenewal
            {
                return $this->lose ? LeaseRenewal::lost() : LeaseRenewal::renewed(new DbInstant(new \DateTimeImmutable('+360 seconds')));
            }

            public function checkpoint(ExecutionLease $lease, StageCheckpoint $checkpoint, int $expectedSeq): CheckpointResult
            {
                throw new \LogicException('Not used by tool');
            }
        };
        $lease = new ExecutionLease(new WorkId(ContractFixtures::id()), ContractFixtures::id(2), 1, ContractFixtures::id(3), new \DateTimeImmutable('+360 seconds'), 1, ContractFixtures::hash(), 0, null, StageCode::Prepare, 'tool-fixture/1');

        return new ExecutionContext($lease, $keeper, $clock, Duration::seconds(240), Duration::seconds(360), new Duration(100), Duration::seconds(30), Duration::seconds(30));
    }
}
