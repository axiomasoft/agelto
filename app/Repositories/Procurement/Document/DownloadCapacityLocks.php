<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Document;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Services\Procurement\Process\Contracts\PublicationLocks;

/** Namespace order lane < storage. This hook runs after lane locks and BEFORE subjects/work. */
final readonly class DownloadCapacityLocks implements PublicationLocks
{
    /** @param list<ProcessUuid> $domains */
    public function __construct(private StorageCapacityRepository $capacity, private array $domains) {}

    public function lockIdentities(): void
    {
        $this->capacity->lockDomains($this->domains);
    }

    public function lockSubjects(): void {}
}
