<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Closure;

/**
 * Кооперативный контекст одного claimed attempt (D13, bp-heartbeat). PHP worker однопоточный:
 * handler вызывает tick() между частями, в idle wait (progressCallback) и перед каждым effect.
 * tick продлевает DB lease CAS не чаще heartbeat interval по monotonic clock и прекращает работу
 * typed выходом: LeaseLost (CAS 0 rows, локальный safety deadline), SliceExhausted (monotonic
 * slice), DependencyUnavailable (DB). Истёкший или отозванный lease не оживляется; heartbeat,
 * checkpoint и новый effect требуют живой lease, late DB-only finalize — только WorkFinalizer.
 * Без alarm/fork heartbeat. Mutable: держит текущий lease и deadlines; tests подменяют
 * LeaseKeeper/ProcessClock, а не наследуют этот класс.
 */
final class ExecutionContext
{
    private ExecutionLease $lease;

    private readonly int $sliceDeadlineNs;

    private int $lastRenewalNs;

    private bool $lost = false;

    public function __construct(
        ExecutionLease $lease,
        private readonly LeaseKeeper $keeper,
        private readonly ProcessClock $clock,
        Duration $slice,
        private readonly Duration $leaseDuration,
        private readonly Duration $heartbeatInterval,
        private readonly Duration $finalizationReserve,
        private readonly Duration $leaseSafety,
    ) {
        if ($heartbeatInterval->milliseconds < 1 || $slice->milliseconds < 1
            || $leaseDuration->milliseconds <= $leaseSafety->milliseconds + 2 * $heartbeatInterval->milliseconds) {
            throw new \InvalidArgumentException('Lease must outlive two heartbeat intervals plus the safety margin.');
        }
        $this->lease = $lease;
        $now = $clock->monotonicNs();
        $this->sliceDeadlineNs = $now + $slice->nanos();
        // Claim только что установил DB lease: отсчёт heartbeat и safety deadline от него.
        $this->lastRenewalNs = $now;
    }

    /**
     * Кооперативная точка: throttled DB heartbeat и проверка slice.
     *
     * @throws LeaseLost|SliceExhausted|DependencyUnavailable
     */
    public function tick(): void
    {
        $now = $this->owned();
        if ($now - $this->lastRenewalNs >= $this->heartbeatInterval->nanos()) {
            $renewal = $this->keeper->heartbeat($this->lease);
            if ($renewal->leaseUntil === null) {
                $this->lost = true;

                throw LeaseLost::forWork($this->lease->workId->value->value);
            }
            $this->lease = $this->lease->renewed($renewal->leaseUntil);
            $this->lastRenewalNs = $now;
        }
        if ($now >= $this->sliceDeadlineNs) {
            throw SliceExhausted::forWork($this->lease->workId->value->value);
        }
    }

    public function remainingSlice(): Duration
    {
        return Duration::nanoseconds($this->sliceDeadlineNs - $this->clock->monotonicNs());
    }

    /** Effect длительностью $needed помещается целиком с finalization reserve; timeout не укорачивается. */
    public function canStart(Duration $needed): bool
    {
        if ($this->lost) {
            return false;
        }
        $now = $this->clock->monotonicNs();
        $end = $now + $needed->nanos();

        return $end + $this->finalizationReserve->nanos() <= $this->sliceDeadlineNs && $end < $this->localLeaseDeadlineNs();
    }

    /** Ожидание до DB instant частями ≤5 s с tick между ними; срок за пределами slice — SliceExhausted сразу. */
    public function sleepUntil(DbInstant $at): void
    {
        $now = $this->owned();
        $target = $now + $at->since($this->clock->dbNow())->nanos();
        if ($target > $this->sliceDeadlineNs) {
            throw SliceExhausted::forWork($this->lease->workId->value->value);
        }
        $chunk = Duration::seconds(5)->nanos();
        while (($remaining = $target - $this->clock->monotonicNs()) > 0) {
            $this->clock->sleep(Duration::nanoseconds(min($remaining, $chunk)));
            $this->tick();
        }
    }

    /** Guzzle/cURL progress callback: тот же throttled tick даже в idle wait. */
    public function progressCallback(): Closure
    {
        return function (mixed ...$progress): void {
            $this->tick();
        };
    }

    /**
     * Guarded progress текущего owner: merged envelope сохраняет provider recovery directive.
     * Любой исход кроме Saved — потеря ownership: handler прекращается без публикации.
     *
     * @throws LeaseLost|DependencyUnavailable
     */
    public function checkpoint(StageCheckpoint $progress): CheckpointResult
    {
        $now = $this->owned();
        $result = $this->keeper->checkpoint($this->lease, $this->lease->guardProgress($progress), $this->lease->checkpointSeq);
        if ($result->checkpointSeq === null) {
            $this->lost = true;

            throw LeaseLost::forWork($this->lease->workId->value->value);
        }
        $this->lease = $this->lease->checkpointed($result);
        $this->lastRenewalNs = $now;

        return $result;
    }

    public function lease(): ExecutionLease
    {
        return $this->lease;
    }

    public function ids(): ExecutionIds
    {
        return new ExecutionIds($this->lease->workId, $this->lease->attemptId, $this->lease->generation);
    }

    /** Monotonic now живого owner; потерянный или локально истёкший lease — LeaseLost. */
    private function owned(): int
    {
        $now = $this->clock->monotonicNs();
        if (! $this->lost && $now >= $this->localLeaseDeadlineNs()) {
            // DB lease не продлён (например, DB недоступна): локальный safety bound останавливает работу.
            $this->lost = true;
        }
        if ($this->lost) {
            throw LeaseLost::forWork($this->lease->workId->value->value);
        }

        return $now;
    }

    private function localLeaseDeadlineNs(): int
    {
        return $this->lastRenewalNs + $this->leaseDuration->nanos() - $this->leaseSafety->nanos();
    }
}
