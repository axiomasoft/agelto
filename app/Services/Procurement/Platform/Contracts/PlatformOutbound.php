<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Outcome\TransportOutcome;
use App\Dto\Procurement\Process\Execution\ExecutionContext;

/** Application composition supplies the existing network owner, never an adapter retry loop. */
interface PlatformOutbound
{
    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome;
}
