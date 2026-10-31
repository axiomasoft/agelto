<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Models\Procurement\Procurement;

/** A transaction-bound result of identity and subject locking, never a queue payload. */
final readonly class LockedProcurement
{
    public function __construct(public ProcurementIdentity $identity, public ?Procurement $procurement, public string $transactionId) {}
}
