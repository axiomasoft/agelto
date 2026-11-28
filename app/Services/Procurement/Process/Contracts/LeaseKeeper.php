<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Execution\CheckpointResult;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Execution\LeaseRenewal;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use Illuminate\Container\Attributes\Bind;

/**
 * DB-порт ownership исполняющего attempt: heartbeat — единственный CAS work
 * row; checkpoint — guarded progress под subject locks. Оба требуют живой DB lease; истёкший lease
 * не оживляется. DB unavailable — typed DependencyUnavailable, не Lost. Tests подменяют этот порт,
 * а не наследуют final ExecutionContext.
 */
#[Bind(WorkStoreRepository::class)]
interface LeaseKeeper
{
    public function heartbeat(ExecutionLease $lease): LeaseRenewal;

    public function checkpoint(ExecutionLease $lease, StageCheckpoint $checkpoint, int $expectedSeq): CheckpointResult;
}
