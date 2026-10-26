<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Process\InvariantRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** bp-sql-recover#6: first tick and then once per 60 monotonic seconds, independent of delivery. */
final class InvariantScan implements ReconcileCategory
{
    public const int INTERVAL_SECONDS = 60;

    private ?int $nextScanNs = null;

    public function __construct(
        private readonly InvariantRepository $invariants,
        private readonly ProcessTransaction $transaction,
        private readonly ProcessClock $clock,
    ) {}

    public function name(): string
    {
        return 'invariant';
    }

    public function reconcile(): int
    {
        $now = $this->clock->monotonicNs();
        if ($this->nextScanNs !== null && $now < $this->nextScanNs) {
            return 0;
        }
        if (DB::connection()->transactionLevel() !== 0) {
            // A nested commit cannot release row locks. Leave cadence due for the next standalone
            // tick; even a healthy nested read could otherwise cause a later caller lock upgrade.
            return 0;
        }
        $violations = $this->transaction->run(fn (): array => $this->invariants->readBatch(RecoveryReconciler::BATCH));
        $changed = 0;
        foreach ($violations as $violation) {
            // Stable IDs outside retry. No read transaction/locks remain when control is mutated.
            $ids = [self::uuid(), self::uuid(), self::uuid(), self::uuid(), self::uuid()];
            if ($this->transaction->run(fn (): bool => $this->invariants->recordAndHold($violation, ...$ids))) {
                $changed++;
            }
        }
        $this->nextScanNs = $now + self::INTERVAL_SECONDS * 1_000_000_000;

        return $changed;
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }
}
