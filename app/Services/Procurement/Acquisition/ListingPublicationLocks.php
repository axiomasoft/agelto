<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\LockedProcurement;
use App\Dto\Procurement\Acquisition\ProcurementIdentity;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Support\Database\PostgresAdvisoryLock;

/** Identity authorities precede crawl-block subjects; procurement rows follow them in table order. */
final class ListingPublicationLocks implements PublicationLocks
{
    /** @var list<LockedProcurement> */
    public array $locked = [];

    /** @param list<ProcurementIdentity> $identities */
    public function __construct(private readonly array $identities, private readonly PostgresAdvisoryLock $advisory,
        private readonly SnapshotFinalizer $snapshots) {}

    public function lockIdentities(): void
    {
        $this->locked = [];
        $this->advisory->acquireProcurements($this->identities);
    }

    public function lockSubjects(): void
    {
        $this->locked = $this->snapshots->lockRows($this->identities);
    }
}
