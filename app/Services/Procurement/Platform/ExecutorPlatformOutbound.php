<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportOutcome;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;

final readonly class ExecutorPlatformOutbound implements PlatformOutbound
{
    public function __construct(private OutboundExecutor $executor, private OutboundAttemptGate $gate, private UrlPolicy $urls, private TransferBudget $budget) {}

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome
    {
        return $this->executor->execute($plan, $context, $this->gate, $this->urls, $this->budget);
    }
}
