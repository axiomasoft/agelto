<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Control;

use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationClaim;
use App\Dto\Procurement\Process\Primitives\DbInstant;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Execution\SystemProcessClock;
use App\Support\Time\Monotonic;
use Closure;

/** Bounded cooperative bridge context; the send gate remains the sole effect authority. */
final class OutboundExecutionContext
{
    private int $lastRenewedNs;

    private readonly int $deadlineNs;

    private bool $lost = false;

    private ?AdapterValidationClaim $adapterClaim = null;

    /** @var list<ProcessUuid> */
    private array $successfulRequestIds = [];

    public function bindAdapter(AdapterValidationClaim $claim): void
    {
        if ($this->adapterClaim !== null || $claim->ownerId->value !== $this->handle->id->value
            || $claim->ownerKind !== AdapterValidationOwnerKind::OutboundOperation) {
            throw new \LogicException('An adapter claim belongs to exactly one full-validation owner.');
        }
        $this->adapterClaim = $claim;
    }

    public function adapterClaim(): ?AdapterValidationClaim
    {
        return $this->adapterClaim;
    }

    public function recordSuccessfulRequest(ProcessUuid $id): void
    {
        if (count($this->successfulRequestIds) >= 64) {
            throw new \LogicException('Full validation request provenance exceeded its bound.');
        }
        $this->successfulRequestIds[] = $id;
    }

    /** @return list<ProcessUuid> */
    public function successfulRequestIds(): array
    {
        return $this->successfulRequestIds;
    }

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
        private readonly ProcessClock $clock = new SystemProcessClock,
    ) {
        $this->lastRenewedNs = Monotonic::nanoseconds();
        $this->deadlineNs = $this->lastRenewedNs + $deadlineSeconds * 1_000_000_000;
    }

    public function lease(): OutboundOperationHandle
    {
        return $this->handle;
    }

    public function remainingSlice(): Duration
    {
        return Duration::nanoseconds($this->deadlineNs - Monotonic::nanoseconds());
    }

    public function dbNow(): DbInstant
    {
        return $this->clock->dbNow();
    }

    public function canStart(Duration $needed): bool
    {
        return ! $this->lost && Monotonic::nanoseconds() + $needed->nanos() + 15_000_000_000 <= $this->deadlineNs;
    }

    /** @throws OutboundOwnershipLost */
    public function tick(): void
    {
        $this->checkpoint?->__invoke();
        $now = Monotonic::nanoseconds();
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
