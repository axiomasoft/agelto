<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\Contract;

use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Outcome\TransportOutcome;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;

/** Deterministic contract port; does not claim transport, concurrency or persistence coverage. */
final class RecordingOutbound implements PlatformOutbound
{
    /** @var list<OutboundRequestPlan> */
    public array $plans = [];

    /** @param list<TransportOutcome> $outcomes */
    public function __construct(private array $outcomes = []) {}

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome
    {
        $this->plans[] = $plan;

        return array_shift($this->outcomes) ?? throw new \LogicException('Unexpected outbound request.');
    }
}
