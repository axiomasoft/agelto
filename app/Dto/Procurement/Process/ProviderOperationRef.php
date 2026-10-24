<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class ProviderOperationRef
{
    public function __construct(public ProcessUuid $logicalRequestId, public ProcessUuid $physicalCallId, public int $requestEpoch)
    {
        ProcessValue::positive($requestEpoch);
    }
}
