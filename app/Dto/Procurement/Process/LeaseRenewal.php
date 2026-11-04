<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Исход heartbeat CAS: Renewed(новый DB lease_until) либо Lost. */
final readonly class LeaseRenewal
{
    private function __construct(public ?DbInstant $leaseUntil) {}

    public static function renewed(DbInstant $leaseUntil): self
    {
        return new self($leaseUntil);
    }

    public static function lost(): self
    {
        return new self(null);
    }

    public function isRenewed(): bool
    {
        return $this->leaseUntil !== null;
    }
}
