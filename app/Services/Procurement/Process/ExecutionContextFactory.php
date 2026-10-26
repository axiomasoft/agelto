<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;

/** Открывает ExecutionContext claimed lease с boot-validated timing policy его stage. */
final readonly class ExecutionContextFactory
{
    public function __construct(
        private LeaseKeeper $keeper,
        private ProcessClock $clock,
        private ProcessPolicy $policy,
    ) {}

    public function open(ExecutionLease $lease): ExecutionContext
    {
        return new ExecutionContext(
            lease: $lease,
            keeper: $this->keeper,
            clock: $this->clock,
            slice: Duration::seconds($this->policy->sliceSeconds($lease->stage)),
            leaseDuration: Duration::seconds($this->policy->leaseSeconds($lease->stage)),
            heartbeatInterval: Duration::seconds($this->policy->heartbeatSeconds),
            finalizationReserve: Duration::seconds($this->policy->finalizationReserveSeconds),
            leaseSafety: Duration::seconds($this->policy->leaseSafetySeconds),
        );
    }
}
