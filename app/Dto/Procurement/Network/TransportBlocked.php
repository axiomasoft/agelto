<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Enums\Procurement\Network\NetworkBlockedReason;

/** Нужна явная операция (resume, credential, policy review); время само не разблокирует. */
final readonly class TransportBlocked implements TransportOutcome
{
    public function __construct(public NetworkBlockedReason $reason) {}
}
