<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/**
 * Heartbeat/checkpoint CAS не нашёл живого owner: lease истёк, generation отозвана recovery или
 * cancel, input сменился. Handler прекращается без попытки публиковать результат и без новых
 * effects; истёкший lease не оживляется.
 */
final class LeaseLost extends ExecutionInterrupted
{
    public static function forWork(string $workId): self
    {
        return new self('Execution lease lost for work '.$workId.'.');
    }
}
