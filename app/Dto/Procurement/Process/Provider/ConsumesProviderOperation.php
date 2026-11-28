<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Provider;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;

/** Explicit proof carried by a domain progress mutation, never by an ordinary heartbeat. */
interface ConsumesProviderOperation
{
    public function consumedPhysicalCallId(): ?ProcessUuid;
}
