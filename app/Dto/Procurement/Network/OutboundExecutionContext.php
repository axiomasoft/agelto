<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\Duration;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use Closure;

/** Bounded cooperative bridge context; the send gate remains the sole effect authority. */
final class OutboundExecutionContext
{
    private int $lastRenewedNs;

    private readonly int $deadlineNs;

    private bool $lost = false;

    /**
     * @param  Closure(OutboundOperationHandle): ?OutboundOperationHandle  $renew
     * @param  Closure(): void|null  $checkpoint  Existing caller cancellation/deadline check.
     */
    public function __construct(
        private OutboundOperationHandle $handle,
        private readonly Closure $renew,
        private readonly ?Closure $checkpoint,
        int $deadlineSeconds,
        private readonly int $renewIntervalSeconds,
    ) {
        $this->lastRenewedNs = hrtime(true);
        $this->deadlineNs = $this->lastRenewedNs + $deadlineSeconds * 1_000_000_000;
    }

    public function lease(): OutboundOperationHandle
    {
        return $this->handle;
    }

    public function remainingSlice(): Duration
    {
        return Duration::nanoseconds($this->deadlineNs - hrtime(true));
    }

    public function canStart(Duration $needed): bool
    {
        return ! $this->lost && hrtime(true) + $needed->nanos() + 15_000_000_000 <= $this->deadlineNs;
    }

    public function tick(): void
    {
        $this->checkpoint?->__invoke();
        $now = hrtime(true);
        if ($this->lost || $now >= $this->deadlineNs) {
            throw new OutboundOwnershipLost('Outbound owner expired.');
        }
        if ($now - $this->lastRenewedNs >= $this->renewIntervalSeconds * 1_000_000_000) {
            $renewed = ($this->renew)($this->handle);
            if ($renewed === null) {
                $this->lost = true;
                throw new OutboundOwnershipLost('Outbound owner lost.');
            }
            $this->handle = $renewed;
            $this->lastRenewedNs = $now;
        }
    }
}
