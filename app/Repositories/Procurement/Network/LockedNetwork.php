<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Network;

use App\Dto\Procurement\Network\PermitEvidence;
use App\Repositories\Procurement\Process\LedgerRow;

/** All tier7 authorities held; arrays are exact keyed sets, never lazy relations. */
final readonly class LockedNetwork
{
    /** @param array<string, LedgerRow> $budgets keyed by scope_key */
    public function __construct(public array $budgets, public LedgerRow $origin, public PermitEvidence $evidence) {}
}
