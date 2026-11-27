<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;

/** Known billed/config errors retain their pinned evidence but never become a generation resume. */
interface ProviderRecoveryPolicy
{
    public function terminalReason(ProviderRecoveryPlan $plan): ?BlockedReason;
}
