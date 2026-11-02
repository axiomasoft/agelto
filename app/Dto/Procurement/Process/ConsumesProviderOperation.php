<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Explicit proof carried by a domain progress mutation, never by an ordinary heartbeat. */
interface ConsumesProviderOperation
{
    public function consumedPhysicalCallId(): ?ProcessUuid;
}
