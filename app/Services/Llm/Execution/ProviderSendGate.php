<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;

interface ProviderSendGate
{
    public function claimSend(AuthorizedProviderOperation $operation, ProviderWireRequest $wireRequest): SendClaimResult;

    public function recordTransportOutcome(string $physicalCallId, ProviderTransportOutcome $outcome): void;
}
