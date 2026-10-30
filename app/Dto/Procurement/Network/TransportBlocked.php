<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\NetworkBlockedReason;

/** Нужна явная операция (resume, credential, policy review); время само не разблокирует. */
final readonly class TransportBlocked implements TransportOutcome
{
    public function __construct(public NetworkBlockedReason $reason, public ?ProcessUuid $lastRequestId = null) {}
}
