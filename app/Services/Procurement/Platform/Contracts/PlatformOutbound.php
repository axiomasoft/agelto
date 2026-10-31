<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransportOutcome;
use App\Dto\Procurement\Process\ExecutionContext;

/** Application composition supplies the existing network owner, never an adapter retry loop. */
interface PlatformOutbound
{
    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome;
}
