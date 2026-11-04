<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\ProcessTransaction;

/** Crash cleanup belongs to the existing reconcile daemon, never a business retry scheduler. */
final readonly class NetworkReconcileCategory implements ReconcileCategory
{
    public function __construct(private LegacyBridge $bridge,
        private AdapterHealthRepository $adapters,
        private NetworkPermitRepository $permits,
        private ProcessTransaction $transaction) {}

    public function name(): string
    {
        return 'outbound_expired_owners';
    }

    public function reconcile(): int
    {
        return $this->bridge->expireStale(100)
            + $this->permits->reap(64)
            + $this->transaction->run(fn (): int => $this->adapters->reap(100));
    }
}
