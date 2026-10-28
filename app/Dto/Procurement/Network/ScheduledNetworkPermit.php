<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** A future reservation, never a physical-send capability. */
final readonly class ScheduledNetworkPermit
{
    public function __construct(public ProcessUuid $id, public NetworkDemandTicket $ticket, public \DateTimeImmutable $slotAt, public \DateTimeImmutable $expiresAt) {}
}
